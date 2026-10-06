<?php

/*
 * S3-10: one trace across processes. The request span → the outbox row → the relay's PRODUCER span
 * (traceparent as an SNS/SQS message attribute) → the consumer's CONSUMER span → whatever the
 * consumer records next. Spans are captured in memory; SNS and SQS are the local emulator.
 */

use App\Platform\Messaging\IdempotentConsumer;
use App\Platform\Messaging\OutboxPublisher;
use App\Platform\Messaging\OutboxRelay;
use App\Platform\Messaging\SqsConsumerRunner;
use App\Platform\Observability\Tracing;
use Aws\Sns\SnsClient;
use Aws\Sqs\SqsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Tests\Fixtures\TestEvent;

uses(RefreshDatabase::class);

const CALLER = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

beforeEach(function () {
    $this->exporter = new InMemoryExporter;
    $this->app->singleton(TracerProviderInterface::class, fn () => new TracerProvider(new SimpleSpanProcessor($this->exporter)));
    $this->app->forgetInstance(Tracing::class);
    $this->app->forgetInstance(OutboxRelay::class);

    /** @return list<SpanDataInterface> */
    $this->spans = fn (?string $name = null) => array_values(array_filter(
        iterator_to_array($this->exporter->getSpans()),
        fn (SpanDataInterface $s) => $name === null || $s->getName() === $name,
    ));
});

describe('HTTP requests', function () {
    beforeEach(function () {
        Route::middleware('api')->get('v1/_trace/{thing}', function () {
            DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent('video-events')));

            return response()->json(['ok' => true]);
        });
        Route::middleware('api')->get('v1/_trace-fail', fn () => throw new RuntimeException('boom'));
    });

    it('continues the caller\'s trace and names the span after the route', function () {
        $response = $this->getJson('/v1/_trace/abc', ['traceparent' => CALLER])->assertOk();

        [$span] = ($this->spans)('GET /v1/_trace/{thing}');
        expect($span->getKind())->toBe(SpanKind::KIND_SERVER)
            ->and($span->getTraceId())->toBe('0af7651916cd43dd8448eb211c80319c')
            ->and($span->getParentSpanId())->toBe('b7ad6b7169203331')
            ->and($span->getAttributes()->get('http.response.status_code'))->toBe(200)
            ->and($response->headers->get('traceresponse'))->toBe("00-0af7651916cd43dd8448eb211c80319c-{$span->getSpanId()}-01");
    });

    it('records the request span as the outbox event\'s trace context', function () {
        $this->getJson('/v1/_trace/abc', ['traceparent' => CALLER])->assertOk();

        [$span] = ($this->spans)('GET /v1/_trace/{thing}');
        $row = DB::table('outbox_messages')->sole();
        expect($row->traceparent)->toBe("00-{$span->getTraceId()}-{$span->getSpanId()}-01")
            ->and(json_decode($row->envelope, true)['trace_id'])->toBe($span->getTraceId());
    });

    it('starts a new trace when the caller sends none (or a malformed one)', function (?string $traceparent) {
        $this->getJson('/v1/_trace/abc', $traceparent ? ['traceparent' => $traceparent] : [])->assertOk();

        [$span] = ($this->spans)('GET /v1/_trace/{thing}');
        expect($span->getTraceId())->toMatch('/^[0-9a-f]{32}$/')->not->toBe('0af7651916cd43dd8448eb211c80319c')
            ->and($span->getParentSpanId())->toBe('0000000000000000');
    })->with([null, 'garbage', '00-00000000000000000000000000000000-b7ad6b7169203331-01']);

    it('marks server errors on the span', function () {
        $this->getJson('/v1/_trace-fail')->assertStatus(500);

        [$span] = ($this->spans)('GET /v1/_trace-fail');
        expect($span->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
            ->and($span->getAttributes()->get('http.response.status_code'))->toBe(500);
    });

    it('does not trace health probes', function () {
        $this->get('/health/live')->assertOk();

        expect(($this->spans)())->toBe([]);
    });
});

describe('across SNS and SQS', function () {
    beforeEach(function () {
        $this->name = 'test-'.Str::lower(Str::random(10));
        $sns = app(SnsClient::class);
        $this->sqs = app(SqsClient::class);
        $this->topicArn = $sns->createTopic(['Name' => $this->name])->get('TopicArn');
        $this->queueUrl = $this->sqs->createQueue(['QueueName' => $this->name])->get('QueueUrl');
        $sns->subscribe([
            'TopicArn' => $this->topicArn, 'Protocol' => 'sqs', 'Attributes' => ['RawMessageDelivery' => 'true'],
            'Endpoint' => $this->sqs->getQueueAttributes(['QueueUrl' => $this->queueUrl, 'AttributeNames' => ['QueueArn']])->get('Attributes')['QueueArn'],
        ]);
    });

    afterEach(function () {
        app(SnsClient::class)->deleteTopic(['TopicArn' => $this->topicArn]);
        $this->sqs->deleteQueue(['QueueUrl' => $this->queueUrl]);
    });

    it('carries one trace from the request through the relay to the consumer and beyond', function () {
        Route::middleware('api')->post('v1/_trace-upload', function () {
            DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent(test()->name)));

            return response()->json([], 201);
        });
        // The consumer does what the media dispatcher does: records the next message.
        $consumer = new class($this->name) extends IdempotentConsumer
        {
            public function __construct(private readonly string $queue) {}

            public function name(): string
            {
                return $this->queue;
            }

            protected function process(array $envelope): void
            {
                app(OutboxPublisher::class)->publish(new TestEvent('media-commands', 'next'));
            }
        };

        $this->postJson('/v1/_trace-upload', [], ['traceparent' => CALLER])->assertCreated();
        app(OutboxRelay::class)->relayBatch();
        app(SqsConsumerRunner::class)->pollOnce($consumer, $this->queueUrl, 1);

        $request = ($this->spans)('POST /v1/_trace-upload')[0];
        $publish = ($this->spans)("publish {$this->name}")[0];
        $consume = ($this->spans)("process {$this->name}")[0];
        expect($publish->getKind())->toBe(SpanKind::KIND_PRODUCER)
            ->and($consume->getKind())->toBe(SpanKind::KIND_CONSUMER)
            // One trace, and each hop is a child of the previous one.
            ->and([$request->getTraceId(), $publish->getTraceId(), $consume->getTraceId()])->each->toBe('0af7651916cd43dd8448eb211c80319c')
            ->and($publish->getParentSpanId())->toBe($request->getSpanId())
            ->and($consume->getParentSpanId())->toBe($publish->getSpanId());

        // What the consumer recorded continues the trace too (the next hop: the media worker).
        $next = DB::table('outbox_messages')->where('topic', 'media-commands')->sole();
        expect($next->traceparent)->toBe("00-0af7651916cd43dd8448eb211c80319c-{$consume->getSpanId()}-01")
            ->and(json_decode($next->envelope, true)['trace_id'])->toBe('0af7651916cd43dd8448eb211c80319c');
    });

    it('still delivers messages that carry no trace context', function () {
        $this->sqs->sendMessage(['QueueUrl' => $this->queueUrl, 'MessageBody' => json_encode(['event_id' => (string) Str::uuid7()])]);
        $consumer = new class($this->name) extends IdempotentConsumer
        {
            public function __construct(private readonly string $queue) {}

            public function name(): string
            {
                return $this->queue;
            }

            protected function process(array $envelope): void {}
        };

        expect(app(SqsConsumerRunner::class)->pollOnce($consumer, $this->queueUrl, 1))->toBe(1);
        expect(($this->spans)("process {$this->name}")[0]->getParentSpanId())->toBe('0000000000000000');
    });
});
