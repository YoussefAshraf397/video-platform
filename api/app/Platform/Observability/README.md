# Observability: tracing

OpenTelemetry tracing for the API and its background processes (S3-10). One upload is one trace, across processes and languages:

```
api           SERVER    POST /v1/uploads/{upload}:complete          (Octane)
└ api         PRODUCER  publish video-events (VideoUploaded)        (outbox:relay)
  └ api       CONSUMER  process media-dispatcher                    (messages:consume)
    └ api     PRODUCER  publish media-commands (MediaProcessRequested)
      └ media-worker CONSUMER  process sqs message                  (Go worker)
          ├ download · probe · transcode h264_360p30 · upload h264_360p30 · … · thumbnails
```

That tree is exactly what a real local run produced (console exporters in all five processes, spans joined by trace id).

## How context travels

| Hop | Carrier |
|---|---|
| Client / ALB → API | `traceparent` request header (`TraceRequests` middleware). The response carries `traceresponse`. |
| Request → outbox | `outbox_messages.traceparent` (and the envelope's `trace_id`) |
| Relay → SNS → SQS | `traceparent` **message attribute** (raw delivery copies SNS attributes to SQS) |
| SQS → consumer | `SqsConsumerRunner` / the Go `sqsworker` continue it in a CONSUMER span |
| Worker → `media-results` | `traceparent` message attribute and `trace_id` in the envelope |

A missing or malformed `traceparent` starts a new trace; nothing fails because of tracing. The trace id is in every log line (`Context`), so logs and traces join on it.

## Using it

- Spans for your own work: `app(Tracing::class)->tracer()->spanBuilder('…')->startSpan()`. Activate the span if work inside it should join the trace.
- Requests, outbox writes, relaying and consuming are traced automatically. Health probes (`/health/*`) are not.

## Configuration

| Variable | Default | |
|---|---|---|
| `OTEL_TRACES_EXPORTER` | `otlp` if `OTEL_EXPORTER_OTLP_ENDPOINT` is set, else `none` | `otlp`, `console` (JSON spans on stderr; under Octane they appear in its log), `none` |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | — | In AWS: the ADOT collector sidecar (`http://localhost:4318`), which forwards to X-Ray |
| `OTEL_SERVICE_NAME` | `api` | |
| `APP_VERSION` | `dev` | Set by CD to the git SHA |

With `none`, spans are still created, so IDs propagate and appear in logs. They just aren't exported. Requests flush their spans when they finish, and CLI consumers flush after each batch.

## Metrics and alarms

These come from CloudWatch, not OpenTelemetry. [`infra/modules/observability`](../../../../infra/modules/observability/) has the dashboard (API requests, errors and latency from the ALB; queue depth, oldest-message age and DLQ depth) and an alarm on every DLQ.

## Not yet built

- The ADOT collector sidecar and X-Ray wiring in ECS (S2-01 / S3-07). Until then, `otlp` has nowhere to send to in AWS.
- Database query spans and outgoing HTTP client spans (auto-instrumentation needs the `opentelemetry` PHP extension).
- Sampling (everything is sampled today), and per-endpoint SLO alarms (p95 latency, 5xx rate) once there's real traffic to set thresholds from.
