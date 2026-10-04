<?php

namespace App\Platform\Messaging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Base class for message consumers. Delivery is at-least-once, so the same event can arrive
 * more than once; handle() runs process() at most once per (consumer, event_id).
 *
 * The dedupe marker and process()'s database writes commit together. If process() throws,
 * neither is saved and the message is retried. Side effects outside this database (HTTP calls,
 * publishing to SNS directly) are not covered; record them through the outbox instead.
 */
abstract class IdempotentConsumer
{
    /** Stable consumer name. It is also the name of the SQS queue it reads, e.g. "media-dispatcher". */
    abstract public function name(): string;

    /** @param array<string, mixed> $envelope */
    abstract protected function process(array $envelope): void;

    /**
     * @param  array<string, mixed>  $envelope
     * @return bool false when the event was already processed (duplicate delivery)
     */
    final public function handle(array $envelope): bool
    {
        $eventId = $envelope['event_id'] ?? null;
        if (! is_string($eventId) || ! Str::isUuid($eventId)) {
            throw new InvalidArgumentException('Message has no valid event_id.');
        }

        return DB::transaction(function () use ($envelope, $eventId) {
            $isNew = DB::table('processed_messages')->insertOrIgnore([
                'consumer' => $this->name(),
                'message_id' => $eventId,
                'processed_at' => now(),
            ]) === 1;

            if ($isNew) {
                $this->process($envelope);
            }

            return $isNew;
        });
    }
}
