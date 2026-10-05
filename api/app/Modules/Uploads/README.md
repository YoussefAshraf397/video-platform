# Uploads module

Direct-to-S3 multipart uploads ([ADR-003](../../../../docs/adr/ADR-003-direct-to-s3-uploads.md), design doc §10). Owns `upload_sessions`. Video bytes go from the client straight to S3; the API only hands out presigned URLs and tracks state. It uses the Videos module through `VideoDirectory` and `VideoLifecycle` only.

## Endpoints

| Endpoint | Notes |
|---|---|
| `POST /v1/videos/{video}/uploads` | Body `{size_bytes, content_type, sha256?}`, plus an `Idempotency-Key` header. Creates the S3 multipart upload and returns `201` with the session and URLs for the first 20 parts. Video → `upload_pending`. |
| `POST /v1/uploads/{id}/parts:sign` | Body `{part_numbers: [..]}` (up to 100). Returns `{parts: [{part_number, size_bytes, url, expires_at}]}`. The first call moves the session to `in_progress` and the video to `uploading`. |
| `GET /v1/uploads/{id}` | **Resume.** Status, `uploaded_parts` (from S3 ListParts, since S3 is the truth for parts) and `next_parts` (fresh URLs for the next 20 missing parts). |
| `POST /v1/uploads/{id}:complete` | Body `{parts: [{part_number, etag}]}` listing every part once, plus an `Idempotency-Key` header. Verifies and assembles the file. Session → `completed`, video → `uploaded`, `VideoUploaded` via the outbox. `200` with `video_status`. |
| `DELETE /v1/uploads/{id}` | Cancel: session → `aborted`, video → `upload_failed` (a new session can start), S3 upload aborted. `204`. |

All endpoints are owner-only. Someone else's session is a `404 UPLOAD_NOT_FOUND`.

**Client loop:** create → PUT each part's bytes to its `url` (3–6 in parallel) and keep each response's `ETag` → ask `parts:sign` for more URLs as needed → after a crash or restart, `GET` the session and carry on from `next_parts` (it also returns the ETags S3 has) → `:complete` with every part's ETag. `size_bytes` on each part tells the client exactly how many bytes to send.

## Completion

`Services\UploadCompletion`, in four steps:

1. **Claim.** One conditional update moves the session to `completing`. A concurrent call gets `409 UPLOAD_COMPLETING` ("retry in a few seconds"). A call after success gets `200` with the same result, whatever its `Idempotency-Key`. If a call crashed mid-way, its claim can be taken over after 2 minutes.
2. **Verify against S3 (ListParts).**
   - The client must list parts 1..N exactly once (`422 INVALID_PART_LIST`).
   - A part S3 doesn't have yet: `409 PARTS_MISSING`, listing them. The session is handed back unchanged so the client can upload them and retry.
   - A client ETag that differs from S3's, or a part of the wrong size: the upload **fails** (`422 UPLOAD_FAILED`, `failure_reason` `upload_etag_mismatch` / `upload_size_mismatch`). The video goes to `upload_failed`, the S3 upload is aborted, and the creator starts a new upload. This is also where part sizes are enforced, since presigned URLs can't limit them.
3. **Complete in S3**, then HEAD the object: its size must equal the declared size (a wrong size fails it and deletes the object). If S3 says the upload no longer exists because an earlier call completed it and then crashed, the object is checked and used.
4. **One transaction:** session `completed`, video → `uploaded` (stepping through `uploading` if the client never asked for more URLs), and one `VideoUploaded`. If the video was deleted or blocked meanwhile, the session still completes but the video doesn't move (§12.3), so there's no `VideoUploaded`.

**Exactly one `VideoUploaded`**, guarded three times: the claim, the conditional `completing → completed` update, and the state machine (a second `uploading → uploaded` is illegal). `ConcurrentCompletionTest` runs 6 separate processes completing the same upload at the same moment: one `ok`, the rest `UPLOAD_COMPLETING`, one event. With the claim removed, the single-process tests catch it. With the claim and the finish guard both removed, the state machine still keeps it to one event.

Responses that the idempotency middleware doesn't store (`409`, `422`) are recomputed from state on retry, so a retry always reflects what actually happened.

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

- A session lives 24 h, and the expiry slides forward on every `parts:sign` / `GET`. An expired session is ended when it's next touched (`410 UPLOAD_EXPIRED` on sign), when a new session starts, or by the sweeper.
- Ending a session (cancel or expiry) commits the status change first, then aborts in S3. If that S3 call fails, the bucket's lifecycle rule (abort incomplete uploads after 7 days, S2-09) cleans up.

## Cleanup and reconciliation (S3-05)

**Sweeper**: `php artisan uploads:sweep`, scheduled every 15 minutes by this module's provider (`withoutOverlapping`, `onOneServer`). It handles each session on its own, so one failure doesn't stop the run, and a missed session is picked up next time.

- Live sessions past `expires_at` → `expired`, video → `upload_failed`, S3 upload aborted.
- Sessions stuck in `completing` (claim older than 2 minutes, because the completing call crashed) → finished from what S3 holds with `UploadCompletion::reconcile` (part and object sizes still checked, no client ETags). If parts are missing, the session is handed back to its previous status, and it can still be completed or expire on schedule.
- It prints `expired / completed / released / failed / errors` counts and exits non-zero if any session errored.

**S3 `ObjectCreated` → `s3-upload-events` → `UploadObjectCreatedConsumer`** (`php artisan messages:consume s3-upload-events`):

- Only our own `:complete` can create a session's object (clients can only PUT parts). So an object whose session isn't `completed` means that call crashed after S3 assembled the file, and the consumer finishes it.
- Usually the session is already completed and the message is a no-op. The queue delays delivery by 90 s so the completing call has normally committed by then.
- If a live call still holds the claim, the consumer throws so SQS redelivers. It takes over once the claim is stale: 5 receives × 60 s is longer than the 2-minute stale window.
- S3 test events, other keys and unknown sessions are ignored. Duplicates are harmless because completion is idempotent.
- Proven end to end locally: a crash after S3 assembly, then the real S3 notification, SQS and the consumer led to a completed session and an `uploaded` video.

## Not yet built

- Whole-file SHA-256 (`sha256`) is stored but only checked by the worker, which reads the file anyway (§10.7).
- Completing a 9,000-part upload can outlast the idempotency middleware's 60-second in-flight lock. A same-key retry during that window gets `409` from the claim, so this is safe, but the lock should be raised if large completes become common.
- A session whose video is deleted mid-upload stops getting URLs (`409`) but stays live until it expires (at most 24 h after its last activity).
- Bucket, queue and notification IaC for AWS (S2-09 / S2-01): CORS, the 7-day abort rule, the `s3-upload-events` queue with its 90 s delay. Locally, `docker/aws/init.sh` sets all of these up except the 7-day abort rule.
- A byte-based daily quota (only session count today) and per-tier size limits (GROWTH).
