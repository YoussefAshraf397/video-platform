# media-worker — Go transcoding service

Consumes `MediaProcessRequested` jobs from the `media-process` SQS queue and turns each upload into an HLS ladder plus thumbnails ([ADR-004](../docs/adr/ADR-004-hls-cmaf.md), [ADR-006](../docs/adr/ADR-006-transcoding-go-ffmpeg.md)). The worker never touches PostgreSQL; it reports results to `media-results`, and Laravel applies the state changes.

**Status:** full pipeline (S2-10, S2-11, S3-08, S3-09): download → probe → validate → transcode and package each rung → upload → results, and thumbnails. Running in AWS is S3-07.

## Layout

```
cmd/media-worker/      main: wiring, signals
internal/config/       environment configuration
internal/sqsworker/    SQS consume loop: heartbeat, ack/release, graceful shutdown
internal/jobs/         validates MediaProcessRequested against ../contracts and decodes it
internal/pipeline/     one job end to end: the order of steps, failure handling, retries
internal/media/        ffprobe wrapper (typed Result) and upload validation rules with rejection codes
internal/ladder/       which renditions to make (rungs, sizes, frame rate, bitrates, H.264 level)
internal/transcode/    FFmpeg encoder + CMAF HLS packaging, master playlist
internal/thumbs/       thumbnail candidates (25/50/75 %, black frames skipped), JPEG + WebP in 3 sizes
internal/storage/      streaming S3 download, parallel/multipart S3 upload
internal/results/      VideoRenditionReady / Completed / Failed to media-results
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
- Every accepted corpus sample is encoded into its full ladder and checked, as are its thumbnails (none may be black). A separate 10 s clip checks 4 s segments and keyframe alignment.
- The pipeline tests run whole jobs against the local S3 and SQS emulator, and skip without `AWS_ENDPOINT_URL`.
- The SQS tests use the local stack and skip if `AWS_ENDPOINT_URL` is unset.
- The probe tests need `ffmpeg`/`ffprobe` on PATH. They use the [golden corpus](internal/testcorpus/CORPUS.md), generated on first use and cached in the OS temp directory. They skip without FFmpeg, except in CI, where they fail.

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `MEDIA_PROCESS_QUEUE` | `media-process` | Queue name |
| `MEDIA_RESULTS_QUEUE` | `media-results` | Where results are sent |
| `SCRATCH_DIR` | OS temp dir | Where jobs download and encode. In ECS, the only writable volume. Leftovers of a killed process are removed at startup. |
| `MAX_ATTEMPTS` | `3` | Deliveries before a job is reported as failed (1–4, so it's always below the queue's `maxReceiveCount` of 5) |
| `X264_PRESET` | `veryfast` | x264 speed/size trade-off |
| `WORKER_CONCURRENCY` | `1` | Jobs per process. Transcoding uses all cores, so scale by adding tasks. |
| `VISIBILITY_TIMEOUT` | `5m` | How far each heartbeat extends the message's invisibility |
| `HEARTBEAT_INTERVAL` | `1m` | Must be ≤ half of `VISIBILITY_TIMEOUT` |
| `SHUTDOWN_GRACE` | `90s` | Time a running job gets after SIGTERM. Keep it below the ECS `stopTimeout` (max 120 s). |
| `LOG_LEVEL` | `info` | `debug`, `info`, `warn`, `error` |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | unset | Export traces over OTLP/HTTP. When unset, trace IDs still appear in logs. |
| `OTEL_TRACES_EXPORTER` | unset | `console` prints spans as JSON on stderr (local debugging) |
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

## A job (`internal/pipeline`)

1. **Download** the source to scratch, streaming (sources can be 10 GB).
2. **Probe and validate** it (below). A rejection fails the job at once.
3. **For each rung, lowest first** (`internal/ladder`): encode and package it (`internal/transcode`), upload its files, rewrite `master.m3u8` to list every rung ready so far, then send `VideoRenditionReady`. The first one is what lets Laravel move the video to READY early. The master is always written *after* the renditions it lists, so it never points at a missing playlist.
4. **Thumbnails** (`internal/thumbs`), uploaded to `thumbs/`.
5. **`VideoProcessingCompleted`**, with every rendition and thumbnail.

**Output layout** (`media/{video_id}/v{n}/`, from MediaProcessRequested):

```
master.m3u8                      Cache-Control: max-age=60 (rewritten as rungs become ready)
h264_360p30/playlist.m3u8        ┐
h264_360p30/init.mp4             │ Cache-Control: max-age=31536000, immutable
h264_360p30/seg_00000.m4s …      │ (paths are versioned and never change)
thumbs/25_1280x720.jpg|webp …    ┘
```

**Encoding (ADR-004):**
- H.264 High at the lowest level that fits (declared in the master's `CODECS`, and tests check it matches the stream).
- AAC-LC 128 kbps stereo at 48 kHz, muxed into each rendition.
- CMAF fMP4 segments of exactly 4 s, with a keyframe exactly on every boundary and nowhere else, so all rungs switch cleanly.
- Constant frame rate (variable-frame-rate sources are converted), capped at 60 fps.
- Rungs 360/480/720/1080 on the **short side**, never above the source. 50/60 fps gets ×1.5 bitrate. Square pixels (anamorphic sources are resized to their display aspect).
- Rotation applied, interlaced sources deinterlaced, HDR tone-mapped to SDR.
- FFmpeg runs with the same demuxer and protocol allowlists as the probe.

**Thumbnails (S3-09):** candidates at 25, 50 and 75 % of the duration. A candidate whose frame is black (mean luma < 32) moves to the nearest non-black frame within ±8 s. Each is written as JPEG and WebP fitted into 1280×720, 640×360 and 320×180, keeping the aspect ratio and never upscaling, so 18 files per video.

**Results and retries:**

| Situation | What happens |
|---|---|
| Media problem (rejection, missing source, unknown profile) | `VideoProcessingFailed` with the code, `retryable: false`; message deleted |
| Anything else (S3 or FFmpeg error) on attempts 1–2 | Error returned; SQS retries with backoff. Nothing is reported yet. |
| …on attempt 3 (`MAX_ATTEMPTS`) | `VideoProcessingFailed` `RETRIES_EXHAUSTED` (or `ENCODER_FAILED`), `retryable: true`; message deleted |
| Shutdown or the worker is killed | Nothing reported; the message reappears and the job runs again |

**Running a job again is safe.** Every output key is derived from the job, so a rerun overwrites the same objects. Every result message's `event_id` is derived from the job and the message, so a rerun re-sends the same IDs and consumers' `event_id` dedupe drops the repeats. Every message is validated against its contract before it is sent.

**Proof (S3-08 acceptance):**
- A real 40 s 1080p upload went through Laravel → `media-process`. The worker binary was killed with `kill -9` right after its first rendition. The message reappeared, and a fresh worker finished the job (attempt 2).
- Afterwards: exactly 67 objects (4 × (10 segments + init + playlist) + master + 18 thumbnails), no strays. Six result messages, all valid against their contracts, with 5 distinct event IDs: the repeated 360p one shares its ID. The master opened with 4 variants and the 1080p rendition decoded end to end.
- `TestRerunAfterInterruptionLeavesNoDuplicates` checks the same thing in CI.

## Tracing

Each job continues the api's trace: the `traceparent` SQS message attribute (set by the api's outbox relay) becomes the parent of the job's CONSUMER span. Every step (download, probe, transcode and upload of each rung, thumbnails) is a child span, and results are sent with `traceparent` too. See [api/app/Platform/Observability](../api/app/Platform/Observability/README.md) for the whole trace.

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

## Not yet built

- The container image and ECS service (S3-07). The image needs FFmpeg with libx264, libwebp and zimg (for HDR tone mapping); Ubuntu's `ffmpeg` package has all three. It should run non-root with a read-only root filesystem and `SCRATCH_DIR` on the scratch volume.
- Laravel consuming `media-results` to move videos to `ready` / `processing_failed` (Sprint 4). Until then, results wait in the queue.
- Captions (ADR-006 step 6), sprites, per-rendition parallel encoding (GROWTH), and the MediaConvert `Encoder` (specified, not built).
- The worker doesn't report the `validating → queued_for_processing → processing` steps yet; the video stays `validating` until the first result.
