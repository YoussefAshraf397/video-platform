<?php

namespace App\Platform\Messaging;

use App\Platform\Observability\Tracing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LengthException;
use LogicException;

/**
 * Records a domain event in the outbox table, inside the caller's database transaction
 * (ADR-005). The event is only sent when that transaction commits, and it is sent even if
 * the process dies right after commit, because the outbox relay picks it up from the table.
 */
final class OutboxPublisher
{
    public function __construct(private readonly Tracing $tracing) {}

    /** SNS rejects larger messages, which would block the outbox, so refuse them up front. */
    private const MAX_MESSAGE_BYTES = 262_144;

    /** @return string the event_id */
    public function publish(OutboxEvent $event): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('OutboxPublisher::publish() must run inside the transaction that changes the aggregate.');
        }

        $id = (string) Str::uuid7();
        $now = now()->utc();

        $envelope = json_encode([
            'event_id' => $id,
            'event_type' => $event->eventType(),
            'schema_version' => $event->schemaVersion(),
            'occurred_at' => $now->format('Y-m-d\TH:i:s.v\Z'),
            'producer' => config('messaging.producer'),
            'aggregate_type' => $event->aggregateType(),
            'aggregate_id' => $event->aggregateId(),
            'aggregate_version' => $event->aggregateVersion(),
            'trace_id' => $this->tracing->currentTraceId() ?? Context::get('trace_id'),
            'payload' => $event->payload(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (strlen($envelope) > self::MAX_MESSAGE_BYTES) {
            throw new LengthException("{$event->eventType()} is larger than 256 KB; put IDs in the payload, not full state.");
        }

        OutboxMessage::query()->insert([
            'id' => $id,
            'topic' => $event->topic(),
            'event_type' => $event->eventType(),
            'aggregate_type' => $event->aggregateType(),
            'aggregate_id' => $event->aggregateId(),
            'aggregate_version' => $event->aggregateVersion(),
            'envelope' => $envelope,
            'traceparent' => $this->tracing->currentTraceparent(),
            'created_at' => $now,
        ]);

        return $id;
    }
}
