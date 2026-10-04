# ADR-005: SQS + SNS for queues and events (Kafka later)

- **Status:** Accepted
- **Date:** 2026-10-04

## Context
We need reliable asynchronous work: processing jobs, notifications, counters, indexing and the deletion saga. Events must be shared across two languages (Laravel and Go). There is no DevOps capacity to run Kafka.

## Decision
- **Transactional outbox** in PostgreSQL: a module writes its state change and an `outbox` row in the same transaction. The `outbox-relay` process publishes the rows and marks them as published.
- **Domain events** are published to **SNS topics** (one per domain area, e.g. `video-events`). Each consumer has **its own SQS queue** subscribed to the topic, with its own **DLQ** (`maxReceiveCount` 5).
- **Commands/jobs** go directly to SQS queues:
  - `media-process` (Laravel → Go)
  - `media-results` (Go → Laravel)
  - `notifications`
  - and so on
- **Laravel jobs** use Laravel's SQS queue driver for Laravel-to-Laravel work. **Cross-language messages use a plain JSON envelope**, not serialized Laravel jobs:
  `event_id, event_type, schema_version, occurred_at, producer, aggregate_id, aggregate_version, trace_id, payload`
- JSON Schemas for every cross-language message live in `contracts/`, and both CI pipelines validate against them.
- **Consumers are idempotent:** either a `processed_messages(consumer, event_id)` table, or natural idempotency through version checks.
- **Telemetry** (player events) uses Kinesis Data Firehose → S3 for raw events, plus SQS for the view-count aggregator.

## Alternatives considered
- **Kafka/MSK from day one:** gives replay and streaming, but costs too much to operate for this team.
- **Redis queues only (Horizon):** fine for Laravel-internal jobs, but less durable and harder to consume from Go.
- **RabbitMQ:** no advantage over SQS on AWS.

## Consequences
- ➕ No message infrastructure to operate. DLQs and visibility timeouts are built in.
- ➖ No replay (DLQ redrive only), and FIFO throughput limits. We rely on per-aggregate versioning instead of global ordering.

## Revisit when
Telemetry exceeds about 10k events/s, or 3+ consumers need replay → add Kinesis/Kafka for telemetry (growth). Kafka becomes the backbone only at large scale.
