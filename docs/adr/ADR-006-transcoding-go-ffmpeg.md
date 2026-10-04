# ADR-006: Go + FFmpeg media workers, MediaConvert as fallback

- **Status:** Accepted
- **Date:** 2026-10-04

## Context
Transcoding is the riskiest and, after CDN egress, the most expensive part of the backend. We have one Go engineer with the capacity to own it. AWS Elemental MediaConvert is faster to adopt but costs more per minute and gives less control.

## Decision
- Build a **Go `media-worker` service** that consumes `media-process` jobs from SQS and runs **FFmpeg/ffprobe**:
  1. **probe** the source
  2. **validate** it (non-retryable reason codes)
  3. **transcode** the ladder (ADR-004)
  4. **package** CMAF HLS + master playlist
  5. generate **3 auto thumbnails**
  6. convert uploaded captions to WebVTT
  7. upload outputs to S3, writing the master playlist **last**
  8. publish the result to `media-results`
- **Laravel owns state.** The worker never writes to PostgreSQL. It reports `VideoRenditionReady`, `VideoProcessingCompleted` or `VideoProcessingFailed`, and Laravel's Video module applies the state transitions.
- **MVP job granularity:** one job per video. The worker processes rungs in order (lowest first) and reports first-playable early. Per-rendition task fan-out comes at growth.
- **Reliability:**
  - extend the SQS visibility timeout with a heartbeat while working;
  - output paths are deterministic, so retries overwrite rather than duplicate;
  - transient errors are retried up to 3 times;
  - deterministic media errors fail immediately with a reason code;
  - DLQ + alarm.
- **Security (untrusted input):**
  - container runs as non-root with a read-only root filesystem and scratch space only;
  - CPU/memory/time limits;
  - the IAM role can only read `uploads/` and write `media/`;
  - no database or secrets access;
  - FFmpeg is pinned and patched regularly.
- **Fallback:** the Go code calls FFmpeg through an `Encoder` interface. A MediaConvert implementation is **specified but not built**. We build it only if the FFmpeg path misses the week-8 milestone or the Go engineer becomes unavailable.
- **Compute:** ECS on EC2 (compute-optimized, Spot with an on-demand base of 1), autoscaled on SQS queue depth. Fargate is acceptable for the first weeks in dev.
- **Safety scanning:** malware scan + CSAM hash-matching through an approved provider must run before publication. Integration is planned for weeks 9–14 and **is a launch blocker**.

## Alternatives considered
- **MediaConvert only:** fastest to ship, but higher unit cost, less control, and vendor lock-in.
- **FFmpeg inside Laravel queue jobs:** possible, but long-running CPU jobs in PHP workers are fragile, and the Go engineer is the natural owner.

## Consequences
- ➕ Lowest unit cost, full control (per-title encoding and codecs later), and a clear owner.
- ➖ Bus factor of 1. Mitigations:
  - a Laravel engineer reviews every Go PR;
  - a runbook;
  - a golden test corpus;
  - the MediaConvert fallback.

## Revisit when
Processing p95 misses its SLO for 2+ consecutive weeks, transcoding volume grows faster than worker ops capacity, or the team gains or loses media expertise.
