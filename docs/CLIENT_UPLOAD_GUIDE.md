# Client upload guide

For web and mobile developers uploading videos to the Video Platform API (ADR-003, S3-11). Files go **straight from the device to S3** in parts, using short-lived URLs the API hands out. Our servers never see the bytes, so uploads of several GB survive slow networks, app restarts and deploys.

A working reference client is in [`examples/upload-client.mjs`](examples/upload-client.mjs). It is plain `fetch`, no dependencies, and implements everything below. It was run against the real local stack: an upload interrupted after a few parts resumed and completed on the next run.

## The flow at a glance

```
1. POST /v1/videos                          → video id (a draft)
2. POST /v1/videos/{video}/uploads          → upload id, part plan, URLs for the first 20 parts
3. PUT  <part url>  (bytes of one part)     → ETag header        ┐ 3–6 at a time,
   POST /v1/uploads/{id}/parts:sign         → more URLs          ┘ retry each part on its own
4. POST /v1/uploads/{id}:complete           → upload completed, video "uploaded"

   After a crash / restart:  GET /v1/uploads/{id}  → parts S3 already has + fresh URLs → continue at 3
   To give up:               DELETE /v1/uploads/{id}
```

All API calls need `Authorization: Bearer <access token>`. The two `POST`s that create something (1, 2) and `:complete` (4) also need an **`Idempotency-Key`** header (see [Retrying API calls](#retrying-api-calls)). Part `PUT`s go to S3 and carry **no** `Authorization` header: the URL is the credential.

## Before you start

| Rule | What the API returns otherwise |
|---|---|
| The user's email is verified | `403 EMAIL_NOT_VERIFIED` |
| `size_bytes` from 1 byte to 10 GiB | `422` (`MAX` / `MIN`) |
| `content_type` is one of `video/mp4`, `video/quicktime`, `video/x-matroska`, `video/webm`, `video/x-msvideo`, `video/mpeg`, `video/3gpp` | `422` (`IN`) |
| At most 25 uploads started per user in any 24 hours | `429 UPLOAD_QUOTA_EXCEEDED` with `Retry-After` (seconds) |
| One live upload per video | `409 UPLOAD_IN_PROGRESS`; the `detail` names the upload to resume |
| The video is a fresh draft, or its last upload failed | `409 VIDEO_NOT_UPLOADABLE` |

Send the file's real type if you know it. If you don't (e.g. no extension), `video/mp4` is fine: the server inspects the actual bytes and rejects anything that isn't a video, whatever the header said.

## 1. Create the video

```http
POST /v1/videos
Idempotency-Key: 6f1c…
{"title": "My trip"}
```

→ `201` with `"id": "y4S2byb8ju0"` (11 characters). Title, description, tags and the rest can be edited later with `PATCH /v1/videos/{id}`.

## 2. Start the upload

```http
POST /v1/videos/y4S2byb8ju0/uploads
Idempotency-Key: 9a7e…
{"size_bytes": 62914560, "content_type": "video/mp4"}
```

```json
{
  "id": "01a10cab-eb98-717e-b5ee-b0ce9b424be3",
  "status": "initiated",
  "size_bytes": 62914560,
  "part_size_bytes": 8388608,
  "total_parts": 8,
  "expires_at": "2026-10-06T12:00:00Z",
  "uploaded_parts": [],
  "next_parts": [
    {"part_number": 1, "size_bytes": 8388608, "url": "https://…", "expires_at": "2026-10-05T12:30:00Z"},
    "… up to 20"
  ]
}
```

- **Save `id` right away** (local storage or a file), together with something that identifies the file: name, size, last-modified. That's what lets you resume after the tab closes or the app restarts.
- **Use the server's part plan; never pick your own.** Part *n* is bytes `[(n−1) × part_size_bytes, min(n × part_size_bytes, size_bytes))`. Every part is `part_size_bytes` except the last, and each part entry also gives its `size_bytes`. Parts are at least 8 MiB, larger for big files (at most 9,000 parts).
- The upload expires after **24 hours without activity**. Every `parts:sign` or `GET` pushes `expires_at` forward.

## 3. Upload the parts

```http
PUT <url from next_parts>
<exactly the bytes of that part>
```

→ `200` with an **`ETag`** response header (e.g. `"2010f6143a7de57ef57f73737cfff69d"`). **Keep every part's ETag**; `:complete` needs them. Browsers can read it because the bucket's CORS rule exposes `ETag`.

- **Concurrency:** 3–6 parts in flight on desktop, 2–3 on mobile networks. More doesn't make one upload faster and hurts everything else on the connection.
- **No extra headers.** Don't send `Authorization` or `Content-MD5`. `Content-Type` is ignored. The URL is signed for exactly one part number of exactly this upload, and it can't write anywhere else.
- **More URLs:** ask for them in batches before you run out:

  ```http
  POST /v1/uploads/{id}/parts:sign
  {"part_numbers": [21, 22, 23, …]}          (up to 100)
  ```

  → `{"parts": [{"part_number", "size_bytes", "url", "expires_at"}, …]}`

- **URLs expire after 30 minutes** (`expires_at`). Refresh any URL with under ~2 minutes left before starting its part. If S3 answers **`403`**, the URL has expired: get a fresh one with `parts:sign` and retry the part.
- **Progress:** bytes of finished parts plus bytes sent of in-flight parts. In browsers, `fetch` has no upload progress events, so use `XMLHttpRequest` and `xhr.upload.onprogress` if you need a smooth progress bar.
- **Persist each ETag as it arrives**, so a restart doesn't re-send finished parts. (It's harmless if you do: re-sending a part just replaces it.)

### Retrying a part

Retry each part on its own; never restart the whole file because one part failed.

| Response | Do |
|---|---|
| Network error, timeout, `408`, `429`, `5xx` (incl. `503 Slow Down`) | Retry the same part with backoff |
| `403` | URL expired: fresh URL via `parts:sign`, then retry |
| `404` `NoSuchUpload` | The upload was cancelled or expired: `GET /v1/uploads/{id}` to see its status |
| Other `4xx` | Bug: don't retry |

**Backoff:** exponential with full jitter: wait `random(0, min(30 s, 1 s × 2^(attempt−1)))`, up to 8 attempts per part. Jitter matters: without it, every client that lost the network at the same moment retries at the same moment.

## 4. Complete

When every part has an ETag:

```http
POST /v1/uploads/{id}:complete
Idempotency-Key: 3c9d…               ← one key for this completion, reused for all its retries
{"parts": [{"part_number": 1, "etag": "\"2010f6…\""}, …, {"part_number": 8, "etag": "…"}]}
```

→ `200` with `"status": "completed"` and `"video_status": "uploaded"`. Processing starts on its own; the video becomes playable as `ready` when the first rendition is done.

- List **every** part from 1 to `total_parts` exactly once. Quotes around ETags are optional; order doesn't matter.
- The server checks every part against S3 (present, same ETag, the planned size) and the final file against `size_bytes`.

| Response | Meaning | Do |
|---|---|---|
| `200` | Done (also returned to any repeat of a completed upload) | Show "processing" |
| `409 PARTS_MISSING` | S3 doesn't have some parts (the `detail` lists them) | Upload them, then complete again |
| `409 UPLOAD_COMPLETING` | Another call is completing it right now | Retry in a few seconds |
| `410 UPLOAD_EXPIRED` | Inactive for 24 h | Start a new upload |
| `422 UPLOAD_FAILED` | A part or the file doesn't match what was declared (`upload_size_mismatch`, `upload_etag_mismatch`) | Start a new upload; the video can take one |
| `422 INVALID_PART_LIST` | Not every part listed exactly once | Bug in the client |

## Resuming

On startup, for any upload id you saved:

```http
GET /v1/uploads/{id}
```

```json
{
  "status": "in_progress",
  "uploaded_parts": [{"part_number": 1, "size_bytes": 8388608, "etag": "\"…\""}, …],
  "next_parts": [{"part_number": 4, "url": "…", …}, …]
}
```

- **Trust `uploaded_parts` over your own notes.** It comes from S3 and includes parts that arrived after your app lost track of them. (In the reference run, the client had stopped after 3 confirmed parts; S3 already had 5.) Take their ETags from here.
- `next_parts` has fresh URLs for the next 20 missing parts. Carry on from step 3.
- `status` other than `initiated` / `in_progress`:
  - `completed`: nothing to do.
  - `aborted` / `expired` / `failed`: start a new upload for the video (step 2).
- If you lost the upload id, `POST …/uploads` answers `409 UPLOAD_IN_PROGRESS` and names it in `detail`.

## Cancelling

```http
DELETE /v1/uploads/{id}          → 204
```

S3 discards the parts. The video goes back to accepting a new upload.

## Retrying API calls

The `POST`s that create things are safe to retry **with the same `Idempotency-Key`**: a retry gets the original response (`Idempotent-Replayed: true`) instead of creating a second video or upload.

- Make a new key (a UUID) for each *intent*: "create this video", "start this upload", "complete this upload". Reuse it for every retry of that intent.
- `409` with the same key means the first request is still running: wait and retry.
- `422` with the same key means you reused a key for a *different* request: that's a bug.
- Retry `429` after `Retry-After`, and `5xx` or network errors with the same backoff as parts.

## Platform notes

- **Browsers:** read parts with `file.slice(start, end)`; nothing needs the whole file in memory. Keep the upload state in IndexedDB so a reload can resume.
- **iOS:** use a background `URLSession` with upload tasks from files (write each part to a temporary file), so uploads continue while the app is suspended. Refresh URLs when the app wakes up, since they may have expired.
- **Android:** run the upload in WorkManager with a network constraint, so it survives process death.
- **Whole-file checksum:** `POST …/uploads` accepts an optional `sha256` (64 hex). The worker verifies it. Hashing a multi-GB file on a phone is slow, so it's optional.

## Don'ts

- Don't upload through your own backend: it defeats the point and doubles the traffic.
- Don't compute part sizes or object keys yourself; use the plan the API gives you.
- Don't call `:complete` while parts are still in flight.
- Don't use a URL for any part but its own (the signature covers the part number).
- Don't retry a failed upload's `:complete` (`422 UPLOAD_FAILED`); start a new upload.

## Trying it locally

With the local stack running (`make up`, then the API on port 8000):

```bash
API=http://localhost:8000 TOKEN=<access token> node docs/examples/upload-client.mjs video.mp4 <video-id>
```

Add `STOP_AFTER=3` to simulate losing the network after three parts, then run it again without it to see the resume. (The local S3 emulator doesn't check signatures or expiry, so the `403` path only shows up against real S3.)
