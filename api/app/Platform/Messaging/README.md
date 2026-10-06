# Messaging: outbox, relay, idempotent consumers

This implements [ADR-005](../../../../docs/adr/ADR-005-messaging-sqs-sns.md): events are published through a transactional outbox to SNS, fanned out to one SQS queue per consumer, and consumed at-least-once by idempotent consumers.

```
module code ──(same DB transaction)──► outbox_messages ──► outbox:relay ──► SNS topic ──► SQS queue per consumer ──► messages:consume ──► IdempotentConsumer
```

## Publishing an event

1. Create an event class in your module's public `Events` namespace implementing `OutboxEvent`. Put IDs and changed fields in the payload, never full state.
2. Publish it **inside** the transaction that changes the aggregate:

```php
DB::transaction(function () use ($video, $outbox) {
    $video->markUploaded();
    $outbox->publish(new VideoUploaded($video));   // OutboxPublisher
});
```

`publish()` throws if called outside a transaction or if the message exceeds SNS's 256 KB limit. If the transaction rolls back, the event is never sent.

## Consuming events

1. Extend `IdempotentConsumer`. `name()` is also the SQS queue name, and `process()` holds the work.
2. Register the class in `config/messaging.php` → `consumers`.
3. Create the queue (with its DLQ) subscribed to the topic, with raw message delivery, in `docker/aws/init.sh` and `infra/`. If the consumer only wants some event types, add a subscription filter policy on `event_type`; still ignore other types in code.
4. Run it with `php artisan messages:consume <name>` (one ECS service per consumer in AWS).

For a message that isn't one of our envelopes (e.g. S3 event notifications), implement `MessageConsumer` directly instead. It has no dedupe marker, so its handling must be idempotent by itself (see the Uploads module's `UploadObjectCreatedConsumer`).

Duplicates are skipped using `processed_messages(consumer, event_id)`. That marker commits in the same transaction as `process()`'s database writes. External side effects (HTTP calls, emails) are **not** deduplicated by this; send them through the outbox or make them idempotent themselves.

A message that fails stays in SQS, is retried after the visibility timeout, and moves to the DLQ after 5 receives.

## Tracing (S3-10)

A trace continues across every hop. `OutboxPublisher` stores the current span's W3C `traceparent` on the outbox row, and puts its trace id in the envelope's `trace_id`. The relay publishes each row in a PRODUCER span that is a child of that context. It sends the span's `traceparent` as an SNS message attribute, which raw delivery turns into an SQS message attribute. `SqsConsumerRunner` runs `handle()` in a CONSUMER span continuing it, so events the consumer records join the same trace. The Go worker does the same on `media-process`, and sends `traceparent` with its results. Details are in [Platform/Observability](../Observability/README.md).

## Running locally

```bash
php artisan outbox:relay                    # long-running; --once to drain and exit
php artisan messages:consume <consumer>     # long-running; --once to drain and exit
```

## Guarantees and limits

- **No loss:** an event whose transaction committed is published eventually, even if the relay is killed. This was verified with `kill -9` mid-batch: 600 events, 0 missing.
- **Duplicates happen:** after a relay crash, up to one batch (default 50) is sent again. Consumers must deduplicate, which `IdempotentConsumer` does.
- **No global ordering.** Use `aggregate_version` to ignore stale events for an aggregate.
- If SNS rejects a message, the relay records `attempts`/`last_error` on that row and retries with backoff (1 s → 30 s). It never skips the row. A row with a growing `attempts` count needs attention (outbox-lag alarm, S3-10).
- `model:prune` (daily) deletes published outbox rows after 7 days and dedupe markers after 15 days.
