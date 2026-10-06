<?php

namespace App\Platform\Messaging;

use App\Platform\Observability\Tracing;
use Aws\Exception\AwsException;
use Aws\Sns\SnsClient;
use Illuminate\Support\Facades\DB;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

/**
 * Moves committed outbox rows to SNS. Delivery is at-least-once: if the process dies after
 * SNS accepted a message but before its row is marked published, the message is sent again
 * on the next run. Consumers deduplicate by event_id (IdempotentConsumer).
 *
 * Several relays can run at once: rows are claimed with FOR UPDATE SKIP LOCKED.
 */
final class OutboxRelay
{
    public function __construct(
        private readonly SnsClient $sns,
        private readonly string $topicArnPrefix,
        private readonly Tracing $tracing,
    ) {}

    /**
     * Publishes up to $limit unpublished rows, oldest first.
     *
     * @return int how many rows were published
     *
     * @throws AwsException when SNS rejects a message. Rows sent before it are still marked
     *                      published, and the failing row records the error and is retried.
     */
    public function relayBatch(int $limit = 50): int
    {
        $failure = null;

        $published = DB::transaction(function () use ($limit, &$failure) {
            $rows = DB::table('outbox_messages')
                ->select(['id', 'topic', 'event_type', 'envelope', 'traceparent'])
                ->whereNull('published_at')
                ->orderBy('id')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            $published = [];
            foreach ($rows as $row) {
                // A PRODUCER span in the trace that recorded the event; consumers continue from it.
                $span = $this->tracing->tracer()->spanBuilder("publish {$row->topic}")
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setParent($this->tracing->parentFrom($row->traceparent))
                    ->setAttribute('messaging.system', 'aws_sns')
                    ->setAttribute('messaging.destination.name', (string) $row->topic)
                    ->setAttribute('messaging.message.id', (string) $row->id)
                    ->setAttribute('messaging.event_type', (string) $row->event_type)
                    ->startSpan();
                $attributes = ['event_type' => ['DataType' => 'String', 'StringValue' => $row->event_type]];
                if (($traceparent = $this->tracing->traceparentOf($span->getContext())) !== null) {
                    // With raw delivery, SNS passes message attributes on as SQS message attributes.
                    $attributes['traceparent'] = ['DataType' => 'String', 'StringValue' => $traceparent];
                }

                try {
                    $this->sns->publish([
                        'TopicArn' => $this->topicArnPrefix.$row->topic,
                        'Message' => $row->envelope,
                        'MessageAttributes' => $attributes,
                    ]);
                    $span->end();
                } catch (AwsException $e) {
                    $span->recordException($e)->setStatus(StatusCode::STATUS_ERROR)->end();
                    $failure = $e;
                    DB::table('outbox_messages')->where('id', $row->id)->update([
                        'attempts' => DB::raw('attempts + 1'),
                        'last_error' => mb_substr($e->getMessage(), 0, 1000),
                    ]);
                    break;
                }
                $published[] = $row->id;
            }

            if ($published !== []) {
                DB::table('outbox_messages')->whereIn('id', $published)->update(['published_at' => now()]);
            }

            return count($published);
        });

        $this->tracing->flush();
        if ($failure !== null) {
            throw $failure;
        }

        return $published;
    }
}
