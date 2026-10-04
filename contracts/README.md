# Message contracts

JSON Schemas for every message that crosses the Laravel ↔ Go boundary ([ADR-005](../docs/adr/ADR-005-messaging-sqs-sns.md)). Both CI pipelines validate their payloads against these schemas (ticket S1-07).

## Layout

```
schemas/envelope.v1.json                     shared envelope
schemas/<event-or-command>.v<major>.json     payload schema, e.g. media-process-requested.v1.json
examples/<event-or-command>.v<major>.json    a valid example payload, used in tests on both sides
```

## Envelope fields

`event_id` (UUID), `event_type`, `schema_version`, `occurred_at` (RFC 3339, UTC), `producer`, `aggregate_type`, `aggregate_id`, `aggregate_version`, `trace_id` (nullable until OpenTelemetry lands in S3-10), `payload`.

## Rules

- Within a major version, changes must be additive: add optional fields only, and never rename, remove or retype a field.
- A breaking change gets a new file (`.v2.json`), and producers publish both versions until every consumer has migrated.
- Payloads carry IDs and changed fields only. A consumer that needs full state re-reads it from the owner.
