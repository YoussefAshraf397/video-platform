# Uploads module

Direct-to-S3 multipart uploads ([ADR-003](../../../../docs/adr/ADR-003-direct-to-s3-uploads.md), design doc §10). Owns `upload_sessions`. Video bytes go from the client straight to S3; the API only hands out presigned URLs and tracks state. It uses the Videos module through `VideoDirectory` and `VideoLifecycle` only.

## Endpoints

| Endpoint | Notes |
|---|---|
| `POST /v1/videos/{video}/uploads` | Body `{size_bytes, content_type, sha256?}`, plus an `Idempotency-Key` header. Creates the S3 multipart upload and returns `201` with the session and URLs for the first 20 parts. Video → `upload_pending`. |
| `POST /v1/uploads/{id}/parts:sign` | Body `{part_numbers: [..]}` (up to 100). Returns `{parts: [{part_number, size_bytes, url, expires_at}]}`. The first call moves the session to `in_progress` and the video to `uploading`. |
| `GET /v1/uploads/{id}` | **Resume.** Status, `uploaded_parts` (from S3 ListParts, since S3 is the truth for parts) and `next_parts` (fresh URLs for the next 20 missing parts). |
| `DELETE /v1/uploads/{id}` | Cancel: session → `aborted`, video → `upload_failed` (a new session can start), S3 upload aborted. `204`. |

All endpoints are owner-only. Someone else's session is a `404 UPLOAD_NOT_FOUND`. Completion (`:complete`, which takes the video to `uploaded`) is S3-04.

**Client loop:** create → PUT each part's bytes to its `url` (3–6 in parallel) and keep each response's `ETag` → ask `parts:sign` for more URLs as needed → after a crash or restart, `GET` the session and carry on from `next_parts`. `size_bytes` on each part tells the client exactly how many bytes to send.

## Rules at creation

| Check | Failure |
|---|---|
| Caller owns the video | `404 VIDEO_NOT_FOUND` / `403 NOT_VIDEO_OWNER` |
| Email verified, account active | `403 EMAIL_NOT_VERIFIED` / `403 ACCOUNT_DISABLED` |
| `size_bytes` 1 B – 10 GiB, `content_type` in `config/uploads.php` | `422` |
| No live session for the video (an expired one is ended on the spot) | `409 UPLOAD_IN_PROGRESS`, naming the session to resume |
| Video is `draft` or `upload_failed` | `409 VIDEO_NOT_UPLOADABLE` |
| At most 25 sessions per user per rolling 24 h | `429 UPLOAD_QUOTA_EXCEEDED` with `Retry-After` |

Two concurrent creates for one video: a partial unique index allows one live session per video. The loser gets `409`, and its S3 upload is aborted.

Part size is `max(8 MiB, size / 9000)` rounded up to a whole MiB, so even 100 GiB stays under S3's 10,000-part limit. Every part has that size except the last.

## Security

- **The server chooses the key:** `uploads/{video_id}/{session_id}/source`. No client input reaches it, and an unknown field such as `key` is a `422`.
- **Each URL can write one part of one upload:** bucket, key, `uploadId` and `partNumber` are covered by the SigV4 signature, and URLs expire after 30 minutes. `PresignedUrlTest` re-computes signatures with the SDK's own signer and shows that changing the session, video, object name, bucket, upload id, part number or lifetime invalidates the URL. (The local S3 emulator doesn't check signatures, so this is tested that way rather than by sending a PUT.)
- The URL inherits the permissions of whoever signed it. In AWS that's the API task role, which S3-07 limits to `uploads/` (and verifies in dev).
- **Part sizes can't be enforced per URL:** S3 doesn't let presigned URLs sign `Content-Length`. A client could send a part of the wrong size, but S3-04 compares the final object size with `size_bytes` and fails the upload on a mismatch. The worker then sniffs and probes the real bytes, whatever `content_type` claimed.

## Lifetimes

- A session lives 24 h, and the expiry slides forward on every `parts:sign` / `GET`. An expired session is ended when it's next touched (`410 UPLOAD_EXPIRED` on sign), or when a new session starts. The sweeper (S3-05) will end the rest every 15 minutes, using `UploadSessions::expire`.
- Ending a session (cancel or expiry) commits the status change first, then aborts in S3. If that S3 call fails, the bucket's lifecycle rule (abort incomplete uploads after 7 days, S2-09) cleans up.

## Not yet built

- `:complete` and size/ETag verification (S3-04), the sweeper and the S3-event reconciliation (S3-05).
- A session whose video is deleted mid-upload stops getting URLs (`409`) but stays live until the sweeper ends it.
- Bucket IaC for AWS with CORS and the 7-day lifecycle rule (S2-09). Locally, `docker/aws/init.sh` creates the bucket with CORS that exposes `ETag`.
- A byte-based daily quota (only session count today) and per-tier size limits (GROWTH).
