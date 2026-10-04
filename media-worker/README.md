# media-worker — Go transcoding service

Consumes `MediaProcessRequested` jobs from the `media-process` SQS queue ([ADR-006](../docs/adr/ADR-006-transcoding-go-ffmpeg.md)). The worker never touches PostgreSQL; it will report results to `media-results` (S3-08).

**Status (S1-10):** the skeleton is complete: config, JSON logging, OpenTelemetry, SQS consumption with heartbeat, and graceful shutdown. Jobs are validated and decoded, but media processing isn't wired in yet: probe arrives in S1-11, transcode/package in S2-10/S2-11, and result events in S3-08.

## Layout

```
cmd/media-worker/      main: wiring, signals
internal/config/       environment configuration
internal/sqsworker/    SQS consume loop: heartbeat, ack/release, graceful shutdown
internal/jobs/         validates MediaProcessRequested against ../contracts and decodes it
internal/telemetry/    slog JSON logger, OpenTelemetry tracer provider
```

## Running locally

```bash
make up                                    # from repo root: SQS emulator + queues
cd media-worker
AWS_ENDPOINT_URL=http://localhost:4566 AWS_ACCESS_KEY_ID=test AWS_SECRET_ACCESS_KEY=test AWS_REGION=us-east-1 \
  go run ./cmd/media-worker
```

Tests: `make test-worker` from the repo root. The SQS tests use the local stack and skip if `AWS_ENDPOINT_URL` is unset.

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `MEDIA_PROCESS_QUEUE` | `media-process` | Queue name |
| `WORKER_CONCURRENCY` | `1` | Jobs per process. Transcoding uses all cores, so scale by adding tasks. |
| `VISIBILITY_TIMEOUT` | `5m` | How far each heartbeat extends the message's invisibility |
| `HEARTBEAT_INTERVAL` | `1m` | Must be ≤ half of `VISIBILITY_TIMEOUT` |
| `SHUTDOWN_GRACE` | `90s` | Time a running job gets after SIGTERM. Keep it below the ECS `stopTimeout` (max 120 s). |
| `LOG_LEVEL` | `info` | `debug`, `info`, `warn`, `error` |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | unset | Export traces over OTLP/HTTP. When unset, trace IDs still appear in logs. |
| `AWS_REGION`, `AWS_ENDPOINT_URL`, credentials | — | Read by the AWS SDK. In ECS, the task role supplies credentials. |

## Message handling

| Outcome | What happens to the message |
|---|---|
| Handler succeeds | Deleted |
| Message breaks the contract (`sqsworker.Poison`) | Released immediately, then reaches the DLQ after `maxReceiveCount` (5) |
| Transient error | Released after a backoff (30 s, 1 m, 2 m … max 15 m), then reaches the DLQ after 5 receives |
| SIGTERM, job finishes within `SHUTDOWN_GRACE` | Deleted; the worker exits |
| SIGTERM, job still running after `SHUTDOWN_GRACE` | Job context cancelled; message released immediately for another worker |

While a job runs, a heartbeat keeps the message invisible, so long transcodes are never picked up twice. SQS still delivers at least once (e.g. if a delete fails), so processors must be safe to repeat. Output keys are deterministic (ADR-006).
