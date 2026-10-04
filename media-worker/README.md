# media-worker — Go transcoding service

Consumes `MediaProcessRequested` jobs from the `media-process` SQS queue ([ADR-006](../docs/adr/ADR-006-transcoding-go-ffmpeg.md)). The worker never touches PostgreSQL; it will report results to `media-results` (S3-08).

**Status (S1-11):** the skeleton is complete: config, JSON logging, OpenTelemetry, SQS consumption with heartbeat, and graceful shutdown. Jobs are validated and decoded. The probe and validation library (`internal/media`) is ready but not yet called from a job, because that needs the S3 download (S3-08). Transcode/package arrive in S2-10/S2-11 and result events in S3-08.

## Layout

```
cmd/media-worker/      main: wiring, signals
internal/config/       environment configuration
internal/sqsworker/    SQS consume loop: heartbeat, ack/release, graceful shutdown
internal/jobs/         validates MediaProcessRequested against ../contracts and decodes it
internal/media/        ffprobe wrapper (typed Result) and upload validation rules with rejection codes
internal/telemetry/    slog JSON logger, OpenTelemetry tracer provider
internal/testcorpus/   golden corpus of source videos + expected outcomes (test-only)
```

## Running locally

```bash
make up                                    # from repo root: SQS emulator + queues
cd media-worker
AWS_ENDPOINT_URL=http://localhost:4566 AWS_ACCESS_KEY_ID=test AWS_SECRET_ACCESS_KEY=test AWS_REGION=us-east-1 \
  go run ./cmd/media-worker
```

Tests: `make test-worker` from the repo root.
- The SQS tests use the local stack and skip if `AWS_ENDPOINT_URL` is unset.
- The probe tests need `ffmpeg`/`ffprobe` on PATH. They use the [golden corpus](internal/testcorpus/CORPUS.md), generated on first use and cached in the OS temp directory. They skip without FFmpeg, except in CI, where they fail.

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

## Probing and validation (`internal/media`)

`Prober.Probe` returns a typed `Result`: duration, size, bitrate, the primary video stream and the audio streams. The video stream includes codec, bit depth, coded and **display** dimensions (after non-square pixels and rotation), rotation, frame rate and VFR, interlacing, colour info and the HDR flag. Cover art (attached pictures) is never counted as video.

`Validate` applies the upload rules (`DefaultLimits`: 1 s to 4 h, short side ≥ 128 px, long side ≤ 7680 px, codec allowlists). Every media problem is a `*Rejection` with a code from the `VideoProcessingFailed` contract and is never retried:

| Code | When |
|---|---|
| `NOT_A_VIDEO` | Magic bytes don't match an allowed container (also catches renamed files and playlists) |
| `CORRUPT_SOURCE` | Looks like a container but FFmpeg can't read it (e.g. truncated upload), probe timeout, or missing duration/dimensions |
| `NO_VIDEO_STREAM` | Audio only, including audio with cover art |
| `UNSUPPORTED_CODEC` | Video or audio codec outside the allowlist |
| `ENCRYPTED_SOURCE` | DRM-protected stream |
| `DURATION_TOO_SHORT` / `DURATION_TOO_LONG` | Outside 1 s to 4 h |
| `RESOLUTION_OUT_OF_RANGE` | Short side < 128 px or long side > 7680 px |

Other errors (ffprobe missing, file unreadable) are infrastructure problems and go through the normal retry path.

**Security:** uploads are untrusted input to FFmpeg, so there are two independent guards against formats (HLS playlists, concat lists) that make FFmpeg open other files or URLs:
1. **Magic-byte sniffing** before ffprobe runs; only MP4/MOV, Matroska/WebM, AVI and MPEG-TS signatures pass.
2. ffprobe runs with **`-format_whitelist`** (those demuxers only) and **`-protocol_whitelist file`**.

A test proves the second guard on its own: without it, ffprobe follows a playlist to another local file.

## Golden corpus (`internal/testcorpus`)

[CORPUS.md](internal/testcorpus/CORPUS.md) lists 23 source videos and the outcome the worker must produce for each.
- **15 accepted:** 16:9, 9:16, 4:3, 4K, 60 fps, VFR, rotated phone video, HDR10, anamorphic DVD, interlaced, 5.1 audio, WebM/VP9, MOV, AVI/MPEG-4, MPEG-TS.
- **8 rejected:** audio only, audio with cover art, too small, too short, FLV, truncated, renamed text file, malicious playlist.

Expectations live as data in `corpus.go`, so later stages (the transcoder in S2-10 checks each sample's `MVPLadder`) test against the same table. `CORPUS.md` is generated from it, and a test fails when the doc is stale.
