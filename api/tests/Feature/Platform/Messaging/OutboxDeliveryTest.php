<?php

/*
 * Integration test against the local stack (`make up`): real PostgreSQL and the real
 * SNS -> SQS emulator, with a throwaway topic and queue per test.
 */

use App\Platform\Messaging\IdempotentConsumer;
use App\Platform\Messaging\OutboxMessage;
use App\Platform\Messaging\OutboxPublisher;
use App\Platform\Messaging\OutboxRelay;
use App\Platform\Messaging\SqsConsumerRunner;
use App\Platform\Observability\Tracing;
use Aws\Exception\AwsException;
use Aws\Sns\SnsClient;
use Aws\Sqs\SqsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\TestEvent;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->name = 'test-'.Str::lower(Str::random(10));
    $this->sns = app(SnsClient::class);
    $this->sqs = app(SqsClient::class);

    $this->topicArn = $this->sns->createTopic(['Name' => $this->name])->get('TopicArn');
    // Visibility timeout 0: a message that failed is redelivered immediately.
    $this->queueUrl = $this->sqs->createQueue([
        'QueueName' => $this->name,
        'Attributes' => ['VisibilityTimeout' => '0'],
    ])->get('QueueUrl');
    $queueArn = $this->sqs->getQueueAttributes(['QueueUrl' => $this->queueUrl, 'AttributeNames' => ['QueueArn']])
        ->get('Attributes')['QueueArn'];
    $this->sns->subscribe([
        'TopicArn' => $this->topicArn,
        'Protocol' => 'sqs',
        'Endpoint' => $queueArn,
        'Attributes' => ['RawMessageDelivery' => 'true'],
    ]);

    $this->consumer = new class($this->name) extends IdempotentConsumer
    {
        /** @var list<string> */
        public array $processed = [];

        public int $failuresLeft = 0;

        public function __construct(private readonly string $queueName) {}

        public function name(): string
        {
            return $this->queueName;
        }

        protected function process(array $envelope): void
        {
            if ($this->failuresLeft > 0) {
                $this->failuresLeft--;
                throw new RuntimeException('transient consumer failure');
            }
            $this->processed[] = $envelope['event_id'];
        }
    };
});

afterEach(function () {
    $this->sns->deleteTopic(['TopicArn' => $this->topicArn]);
    $this->sqs->deleteQueue(['QueueUrl' => $this->queueUrl]);
});

function publishEvents(object $test, int $count): array
{
    return DB::transaction(fn () => array_map(
        fn ($i) => app(OutboxPublisher::class)->publish(new TestEvent($test->name, "agg-{$i}")),
        range(1, $count),
    ));
}

/** Polls until the queue is empty; returns how many deliveries were received. */
function drainQueue(object $test): int
{
    $runner = app(SqsConsumerRunner::class);
    $received = 0;
    while (($n = $runner->pollOnce($test->consumer, $test->queueUrl, waitSeconds: 1)) > 0) {
        $received += $n;
    }

    return $received;
}

it('loses no events when the relay dies mid-batch, and consumers process each event once', function () {
    $eventIds = publishEvents($this, 5);
    $relay = fn (SnsClient $sns) => new OutboxRelay($sns, config('messaging.sns_topic_arn_prefix'), app(Tracing::class));

    // The relay "dies" after SNS accepted 3 messages, before its transaction committed.
    $sent = 0;
    $dyingSns = Mockery::mock(SnsClient::class);
    $dyingSns->shouldReceive('publish')->andReturnUsing(function (array $args) use (&$sent) {
        if (++$sent > 3) {
            throw new RuntimeException('relay process killed');
        }

        return $this->sns->publish($args);
    });
    expect(fn () => $relay($dyingSns)->relayBatch())->toThrow(RuntimeException::class, 'relay process killed')
        ->and(OutboxMessage::whereNotNull('published_at')->count())->toBe(0);

    // Restarted relay publishes everything, including the 3 already sent.
    expect($relay($this->sns)->relayBatch())->toBe(5)
        ->and(OutboxMessage::whereNull('published_at')->count())->toBe(0);

    expect(drainQueue($this))->toBe(8)                       // 3 duplicates delivered...
        ->and($this->consumer->processed)->toHaveCount(5)    // ...but each event processed once
        ->and($this->consumer->processed)->toEqualCanonicalizing($eventIds)
        ->and(DB::table('processed_messages')->where('consumer', $this->name)->count())->toBe(5);
});

it('retries a message whose processing failed, without recording it as processed', function () {
    [$eventId] = publishEvents($this, 1);
    app(OutboxRelay::class)->relayBatch();
    $this->consumer->failuresLeft = 1;

    $runner = app(SqsConsumerRunner::class);
    $runner->pollOnce($this->consumer, $this->queueUrl, waitSeconds: 1);
    expect($this->consumer->processed)->toBe([])
        ->and(DB::table('processed_messages')->where('consumer', $this->name)->count())->toBe(0);

    drainQueue($this);
    expect($this->consumer->processed)->toBe([$eventId]);
});

it('publishes pending events with the outbox:relay command', function () {
    publishEvents($this, 2);

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    expect(OutboxMessage::whereNull('published_at')->count())->toBe(0)
        ->and(drainQueue($this))->toBe(2);
});

it('records the error and keeps the row pending when SNS rejects a message', function () {
    [$eventId] = publishEvents($this, 1);
    $this->sns->deleteTopic(['TopicArn' => $this->topicArn]);   // publishing now fails with NotFound

    expect(fn () => app(OutboxRelay::class)->relayBatch())->toThrow(AwsException::class);

    $row = OutboxMessage::findOrFail($eventId);
    expect($row->published_at)->toBeNull()
        ->and($row->attempts)->toBe(1)
        ->and($row->last_error)->not->toBeEmpty();
});
