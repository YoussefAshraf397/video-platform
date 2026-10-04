# Message contracts

JSON Schemas (draft 2020-12) for every message that crosses the Laravel ↔ Go boundary, and for the domain events other consumers subscribe to ([ADR-005](../docs/adr/ADR-005-messaging-sqs-sns.md)). Both test suites validate against them, so a producer that drifts from its contract fails CI on either side.

## Layout

```
schemas/common.v1.json                       shared definitions (uuid, timestamp, rendition, …)
schemas/envelope.v1.json                     the envelope every message uses
schemas/<message>.v<major>.json              one schema per message, e.g. media-process-requested.v1.json
examples/<message>.v<major>.json             a valid example for each message schema
contracts.go                                 Go package that embeds the schemas (module `videoplatform/contracts`)
contracts_test.go                            Go tests
```

PHP validation lives in [api/tests/Support/Contracts.php](../api/tests/Support/Contracts.php), with tests in [api/tests/Unit/ContractsTest.php](../api/tests/Unit/ContractsTest.php).

## Messages

| Schema | Direction | Queue | Meaning |
|---|---|---|---|
| `media-process-requested.v1` | api → media-worker | `media-process` | Transcode one source into an HLS ladder |
| `video-rendition-ready.v1` | media-worker → api | `media-results` | Another rendition is playable; the first one makes the video READY |
| `video-processing-completed.v1` | media-worker → api | `media-results` | All renditions, master playlist and thumbnails are written |
| `video-processing-failed.v1` | media-worker → api | `media-results` | Processing stopped for good (non-retryable error, or retries exhausted) |
| `video-state-changed.v1` | api → subscribers | topic `video-events` | A video changed status, one per transition. `event_type` is `VideoUploaded`, `VideoPublished`, `VideoUnpublished`, `VideoBlocked`, `VideoDeleted` or `VideoStateChanged` |

## Envelope fields

`event_id` (UUID), `event_type`, `schema_version`, `occurred_at` (RFC 3339, UTC), `producer`, `aggregate_type`, `aggregate_id`, `aggregate_version`, `trace_id` (null until OpenTelemetry lands in S3-10), `payload`.

## Rules

- **Schemas are strict:** unknown fields are rejected, so a producer can't add or misspell a field without updating the schema in the same PR. **Consumers must still ignore fields they don't know**, because a newer producer may be deployed first.
- Within a major version, changes must be additive: add optional fields only, and never rename, remove or retype a field. An enum may gain values, so consumers must handle unknown ones (e.g. error codes).
- A breaking change gets a new file (`.v2.json`), and producers publish both versions until every consumer has migrated.
- Payloads carry IDs and changed fields only. A consumer that needs full state re-reads it from the owner.

## Adding a message

1. Add `schemas/<message>.v1.json`, built on `envelope.v1.json` like the existing ones (pin `event_type`, `schema_version`, `producer`, `aggregate_type`).
2. Add `examples/<message>.v1.json`. Both test suites pick up new files automatically and check the example plus six "broken message" variants.
3. In the producer's tests, validate what the code actually emits:
   - PHP: `expect(Contracts::violations('<message>.v1', $json))->toBeNull()`
   - Go: `validator.Validate("<message>.v1", data)` (import `videoplatform/contracts`; the media worker uses a `replace` directive pointing at `../contracts`)

## Running

```bash
make test-contracts   # Go
make test-api         # PHP (includes the contract tests)
```
