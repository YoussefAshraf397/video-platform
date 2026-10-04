# Sprint Plan: Weeks 1–8 (Foundations → Walking Skeleton)

**Goal for week 8:** in the **dev** AWS environment, a user can register, create a channel, upload a large video (with resume) directly to S3, have it transcoded by the Go worker into an HLS ladder, publish it, and play it through CloudFront with signed cookies. Every step is traceable and covered by an automated end-to-end smoke test.

**References:** [SYSTEM_DESIGN.md](SYSTEM_DESIGN.md) · [ADRs](adr/README.md)

## Team

| Code | Person | Role in weeks 1–8 |
|---|---|---|
| **L1** | Laravel engineer (lead/platform) | Repo, CI/CD, AWS/IaC, auth, outbox/events, observability |
| **L2** | Laravel engineer | Users, channels, video metadata + state machine, playback |
| **L3** | Laravel engineer | Upload system, S3 integration, scheduler jobs |
| **G** | Go engineer | Media worker (probe → transcode → HLS → thumbnails → results) |

## Cadence
- **Sprint length:** 2 weeks.
- **Sprint 1 starts:** Monday of week 1.
- **Rituals:** planning (Mon, 1 h) · daily standup (15 min) · demo + retro (last Fri, 1.5 h).
- **Code review:** every PR needs 1 approval. **Go PRs are reviewed by L1 or L3**, to spread Go knowledge (bus-factor mitigation).
- **Definition of Done (every ticket):**
  - merged to `main` with CI green, including unit/integration tests;
  - deployed to dev automatically;
  - logs/metrics/traces exist for the new path;
  - API changes reflected in OpenAPI;
  - cross-language message changes reflected in `contracts/`;
  - docs/runbook updated if behavior is operational.
- **Estimates:** S ≈ ≤1 day · M ≈ 2–3 days · L ≈ 4–5 days.

---

## Sprint 1 (weeks 1–2): Decisions, repo, local env, AWS foundations

**Sprint goal:** everyone can run the stack locally with one command, CI runs on every PR, the dev AWS account exists, and the Go worker can turn a local file into HLS.

| ID | Ticket | Owner | Size | Acceptance criteria |
|---|---|---|---|---|
| S1-01 | Architecture review of SYSTEM_DESIGN.md + accept ADRs 001–006, 017, 021; pick Terraform vs CDK; pick Octane server (FrankenPHP/RoadRunner) | All (L1 leads) | S | ADR statuses confirmed; open questions logged as tickets |
| S1-02 | Monorepo skeleton (`api/`, `media-worker/`, `contracts/`, `infra/`, `docs/`, `docker/`), branch protection, PR template, CODEOWNERS | L1 | S | Structure exists; `main` protected; CODEOWNERS routes Go PRs to G + one Laravel reviewer |
| S1-03 | Local env: docker compose with PostgreSQL, Redis, LocalStack (S3, SQS, SNS) or MinIO + ElasticMQ, Mailpit; `make up` / `make test` | L1 | M | Fresh clone → working stack in < 10 min following README |
| S1-04 | Laravel app bootstrap: module structure (`app/Modules/*`), architecture tests enforcing boundaries, Octane, health endpoints (`/health/live`, `/health/ready`) | L2 | M | Arch test fails if a module imports another module's models; readiness checks DB + Redis |
| S1-05 | API conventions package: RFC 9457 error format, request ID middleware, cursor pagination helper, `Idempotency-Key` middleware (Redis, 24 h), OpenAPI generation | L2 | M | Each has tests; sample endpoint demonstrates all four |
| S1-06 | Transactional outbox: `outbox` table, `OutboxPublisher` service (write in same txn), `outbox-relay` command (SKIP LOCKED polling → SNS), `processed_messages` table + idempotent consumer base class | L1 | L | Killing the relay mid-batch loses no events; duplicate SQS delivery processed once (integration test) |
| S1-07 | Message envelope JSON Schema + first schemas: `MediaProcessRequested`, `VideoRenditionReady`, `VideoProcessingCompleted`, `VideoProcessingFailed`; validation in PHP & Go CI | L3 + G | M | Both CIs fail on schema-incompatible payload; example payloads in `contracts/examples` |
| S1-08 | CI pipelines: PHP (Pint, PHPStan/Larastan, Pest), Go (golangci-lint, go test, govulncheck), secret scan, dependency scan, Docker build + image scan | L1 | M | Pipeline < 10 min; required checks on PRs |
| S1-09 | AWS: Organization with non-prod/prod accounts, IaC state backend, dev VPC (3 AZ, private subnets), ECR repos | L1 | M | `infra/` applies cleanly to dev; no long-lived IAM user keys (SSO/OIDC for CI) |
| S1-10 | Go worker skeleton: config, structured logging, OpenTelemetry, graceful shutdown, SQS consumer loop with visibility-timeout heartbeat | G | M | Consumes from local ElasticMQ/LocalStack; SIGTERM finishes or releases current message |
| S1-11 | Go: `ffprobe` wrapper → typed `ProbeResult` (duration, streams, codecs, w/h, rotation, SAR/DAR, fps, HDR flag) + validation rules with reason codes | G | M | Unit tests against 10 sample files incl. rotated, vertical, no-audio, corrupt |
| S1-12 | Golden test corpus v1 (small files committed or fetched from bucket): 16:9/9:16/4:3, 30/60 fps, rotated phone video, VFR, no audio, corrupt, >1080p source | G | S | Documented list with expected outcomes |

**Sprint 1 demo:**
- `make up` works locally.
- A PR runs the full CI.
- The Go worker probes and validates all corpus files.
- An outbox event goes from Laravel → SNS → SQS locally.

---

## Sprint 2 (weeks 3–4): Auth, users, channels, deploy pipeline; Go transcoding

**Sprint goal:** auth works end to end in dev, every merge auto-deploys to dev, and the Go worker produces a correct HLS ladder locally.

| ID | Ticket | Owner | Size | Acceptance criteria |
|---|---|---|---|---|
| S2-01 | ECS dev environment: Fargate services `api`, `worker`, `scheduler`, `outbox-relay`; ALB; RDS PG (single-AZ in dev); ElastiCache; Secrets Manager wiring | L1 | L | Services healthy in dev; secrets never in images/env files in repo |
| S2-02 | CD to dev: build once (image digest), deploy on merge, run migrations safely (one-off task), post-deploy smoke (`/health/ready`) | L1 | M | Merge → live in dev < 15 min; failed smoke triggers rollback to previous digest |
| S2-03 | Auth: register (Argon2id), email verification (Mailpit locally / SES sandbox in dev), login, access JWT (15 min, asymmetric) + refresh token (opaque, hashed, rotation + reuse detection), logout, logout-all, sessions list/revoke | L1 | L | Reused refresh token revokes the whole family; no account enumeration; tests cover all flows |
| S2-04 | Password reset + `min_iat` revocation check middleware; auth rate limits (login per IP & account, register per IP) | L1 | M | Reset revokes all sessions; 429 with `Retry-After` past limits |
| S2-05 | Users module: `GET/PATCH /me`, public profile by handle, handle uniqueness (case-insensitive), user status (active/suspended) | L2 | M | Suspended user's tokens rejected; handle collisions return validation error |
| S2-06 | Channels module: create (1 per user), get by id/handle, update; `public_id`; `channel_stats` table; `ChannelCreated` via outbox | L2 | M | Second channel creation rejected; events in outbox |
| S2-07 | Authorization policy layer (Laravel Policies + central `Can` service) with test matrix scaffold (role × resource × action) | L2 | M | Matrix tests run in CI; adding a resource requires adding matrix rows |
| S2-08 | Upload module design spike → schema: `upload_sessions` table, state model, S3 client wrapper (CreateMultipartUpload, presign UploadPart, ListParts, Complete, Abort) against LocalStack | L3 | M | Integration test performs full multipart flow against LocalStack |
| S2-09 | S3 buckets via IaC (dev): `uploads` (private, CORS for web origin, abort-incomplete 7 d lifecycle), `media` (private), `images`; block public access; SSE | L3 + L1 | S | Public access impossible; CORS exposes `ETag` |
| S2-10 | Go: transcode ladder (H.264 High + AAC 128k), rung selection (≤ source short side), fixed GOP aligned to 4 s, VFR→CFR, rotation handling | G | L | Corpus: correct rungs, no upscaling, A/V sync within 1 frame, durations ±100 ms |
| S2-11 | Go: CMAF HLS packaging + master playlist (BANDWIDTH/AVERAGE-BANDWIDTH/CODECS/RESOLUTION/FRAME-RATE), deterministic output layout `media/{video_id}/v{n}/…` | G | M | Output plays in hls.js and Safari; `mediastreamvalidator`/hls validator passes (or equivalent check) |

**Sprint 2 demo:**
- Register → verify email → log in → create channel, all in **dev AWS**.
- Locally, the Go worker turns a 1080p phone video into a playable HLS ladder.

---

## Sprint 3 (weeks 5–6): Video metadata + uploads + worker in AWS

**Sprint goal:** a creator can create a video draft and upload a multi-GB file with resume to S3 in dev. Completion emits `VideoUploaded`, and the Go worker in AWS processes it.

| ID | Ticket | Owner | Size | Acceptance criteria |
|---|---|---|---|---|
| S3-01 | Videos module: `videos` table (status, visibility, state_version, public_id…), `POST/GET/PATCH/DELETE /v1/videos`, tags, categories (seeded) | L2 | L | Owner-only edits; ETag/If-Match on PATCH returns 412 on conflict |
| S3-02 | Video state machine (§12 of the design doc): transition service with conditional updates + outbox event per transition; property-based tests for illegal transitions | L2 | M | Illegal transitions rejected & logged; every transition emits exactly one event |
| S3-03 | Upload API: create session (limits: 10 GB, content-type allowlist, per-user daily quota, verified email required), part URL batches, `GET` status/resume via ListParts, abort | L3 | L | Matches ADR-003; URLs cannot write to any key except the session's |
| S3-04 | Upload completion: idempotent `:complete` (Idempotency-Key + conditional transition), size/ETag verification, `VideoUploaded` via outbox, video → UPLOADED | L3 | M | Two concurrent completes → one event (test); mismatched size → FAILED with reason |
| S3-05 | Scheduler: expired-session sweeper (every 15 min) + S3 `ObjectCreated` → SQS reconciliation consumer | L3 | M | Expired sessions aborted in S3; orphaned completed objects get sessions completed |
| S3-06 | Media dispatcher (Laravel): consume `VideoUploaded` → create `video_processing_jobs` row (unique video+version) → send `MediaProcessRequested` to `media-process` queue | L1 | M | Duplicate `VideoUploaded` creates one job |
| S3-07 | Go worker in AWS: ECS service on EC2 Spot capacity provider (on-demand base 1), IAM role limited to `uploads/` read + `media/` write + its queues, non-root, read-only rootfs + scratch volume, autoscaling on queue depth | G + L1 | L | Worker processes a job in dev; IAM denies access to other prefixes (tested) |
| S3-08 | Go: S3 streaming download/upload (multipart for outputs), master playlist written last, progress heartbeats, publish `VideoRenditionReady` (after first rung) + `VideoProcessingCompleted`/`Failed` to `media-results` | G | L | Killing the worker mid-job → message reappears → job completes, no duplicate outputs |
| S3-09 | Go: thumbnails (3 candidates at 25/50/75%, skip black frames), JPEG + WebP in 3 sizes | G | M | Thumbnails present and non-black for corpus |
| S3-10 | Observability baseline: OpenTelemetry in Laravel + Go, trace ID propagated through SQS message attributes; CloudWatch dashboards for API RED + queue depth/age + DLQ; alarms on DLQ > 0 | L1 | M | One upload's trace spans API → outbox → dispatcher → worker → results |
| S3-11 | Client upload guide (for web team): chunking, concurrency 3–6, retry/backoff with jitter, resume, URL refresh | L3 | S | Doc in `docs/`; reviewed by web developer |

**Sprint 3 demo:**
- Upload a 3 GB file from the browser (or a script). Kill the network halfway, resume, and complete.
- The worker in dev produces HLS + thumbnails.
- Show the trace end to end.

---

## Sprint 4 (weeks 7–8): Processing results, publish, playback — walking skeleton complete

**Sprint goal:** the full path works in dev: upload → process → READY → publish → play via CloudFront with signed cookies. It is guarded by an automated E2E smoke test.

| ID | Ticket | Owner | Size | Acceptance criteria |
|---|---|---|---|---|
| S4-01 | Results consumer (Laravel): consume `media-results` → write `video_variants`, `video_assets`, `thumbnails` → transitions PROCESSING → READY (on first rendition) → full completion; failures → PROCESSING_FAILED with reason | L2 | M | Duplicate/out-of-order results handled via `aggregate_version`; creator sees status via `GET /videos/{id}/processing` |
| S4-02 | Publish/unpublish endpoints with guards (READY, title present, not blocked); visibility public/unlisted/private; `publish_on_ready` flag | L2 | M | Guards tested; `VideoPublished` emitted |
| S4-03 | CloudFront distribution for `media.` (dev domain): OAC to `media` bucket, path-only cache key, cache policies (segments 1 y, master 2 min, errors 5 s), signed-cookie trusted key group; key pair in Secrets Manager | L1 | M | Direct S3 access denied; unsigned request → 403; cache hit on second request |
| S4-04 | Playback API `GET /v1/videos/{id}/playback`: access policy (status, visibility, owner, age flag), signed cookies scoped to `/media/{video_id}/v{n}/*`, TTL = duration + 30 min (1–6 h), response per design §13.7; policy cache with invalidation on state change | L2 | L | Authorization matrix tests: private/unlisted/draft/blocked × anon/owner/other; p95 < 150 ms in dev load check |
| S4-05 | Processing failure handling: Go error classification (transient vs deterministic), max 3 retries, DLQ; Laravel admin-only endpoint to list failed jobs + retry | G + L3 | M | Corrupt file fails fast with reason code; transient error retried; DLQ alarm fires in dev test |
| S4-06 | Captions: upload SRT/VTT (presigned single PUT) → Go converts to WebVTT → referenced in master playlist | G | M | Caption toggle works in hls.js player |
| S4-07 | Minimal test player page (internal, dev only) using hls.js: calls playback API, sets cookies, plays, shows rendition switching | L3 | S | Used for demos & QA until web app is ready |
| S4-08 | E2E smoke test (runs after every dev deploy): register → channel → draft → multipart upload (small file) → wait READY → publish → playback API → fetch master + one segment via CloudFront | L3 | M | Runs in CD; failure blocks promotion; < 5 min |
| S4-09 | Rate limits for upload creation, part signing, playback auth (per design §24.3) | L1 | S | 429s with headers; tests |
| S4-10 | Go: runbook + architecture README for `media-worker` (how to deploy, scale, debug a stuck job, redrive DLQ, switch to MediaConvert fallback) | G | S | Reviewed by a Laravel engineer who successfully debugs a seeded failure using only the runbook |
| S4-11 | Sprint 5–6 planning prep: refine backlog for hardening (load test, CSAM/malware scanning provider integration — **launch blocker**), engagement (comments, reactions, subscriptions, history) | L1 + all | S | Backlog refined and estimated |

**Sprint 4 demo (milestone):**
- Live walkthrough of the full path in dev.
- Show a private video being denied to another user.
- Kill a worker mid-transcode and show it recover.
- Show the E2E smoke test passing in the pipeline.

---

## Week-8 exit criteria (go / no-go for continuing with FFmpeg)

| Criterion | Target |
|---|---|
| E2E smoke test green on every deploy for 5 consecutive days | Required |
| Golden corpus: all valid files playable, invalid files fail with correct reason codes | Required |
| 10-min 1080p video: time to first playable rendition in dev | ≤ 10 min |
| Worker crash or Spot interruption recovers without duplicates | Required |
| Private/draft videos inaccessible to non-owners (playback API and direct CloudFront/S3) | Required |
| Trace visible across Laravel → SQS → Go → Laravel | Required |

If the media criteria are missed, implement the **MediaConvert fallback** (ADR-006) in weeks 9–10 instead of continuing to harden FFmpeg.

## Risks during weeks 1–8

| Risk | Mitigation |
|---|---|
| G is blocked or unavailable → media path stalls | Encoder interface; L1/L3 review all Go PRs; runbook (S4-10); MediaConvert fallback |
| L1 overloaded (infra + auth + outbox + observability) | L3 takes S2-09 and the E2E smoke test; push MFA/admin SSO to sprint 5 if needed |
| Infra takes longer than planned | Keep local development fully working on LocalStack so feature work is never blocked by AWS |
| Web client not ready for demos | Internal hls.js test page (S4-07) and upload script |
| Scope creep (comments, search…) before skeleton works | Not started until Sprint 4 demo passes |

## Next (weeks 9–22, outline)
- **Weeks 9–10:** hardening + safety scanning integration (malware + CSAM hash-match: launch blocker), staging environment, load test of the skeleton.
- **Weeks 11–14:** reactions + counters, comments + replies, subscriptions, watch history / continue watching, in-app notifications + transactional email.
- **Weeks 15–18:** PostgreSQL FTS search + autocomplete, trending v1, home & subscriptions feed, analytics ingestion + view counting.
- **Weeks 19–22:** reports, moderation queue & actions, admin console APIs, prod environment hardening, pen test, DR restore drill → **beta**.
