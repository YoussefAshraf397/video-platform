# ADR-003: Direct-to-S3 presigned multipart uploads

- **Status:** Accepted
- **Date:** 2026-10-04

## Context
Source videos can be up to 10 GB at the MVP. Proxying uploads through PHP would tie up workers for minutes or hours, need huge bandwidth on API instances, and break whenever a deploy happens. Creators upload over unreliable networks and need to be able to resume.

## Decision
- Clients upload **directly to a private S3 bucket** using **presigned multipart upload URLs** issued by the Laravel Upload module.
- Flow: `POST /v1/videos` (draft) → `POST /v1/videos/{id}/uploads` (session + first batch of part URLs) → `PUT` parts to S3 → `POST /v1/uploads/{id}/parts:sign` (more URLs) → `POST /v1/uploads/{id}:complete`.
- The **server chooses the object key** (`uploads/{video_id}/{session_id}/source`). URLs expire in 15–60 min and are issued in batches.
- Part size = `max(8 MB, ceil(size / 9000))`, which stays under S3's limit of 10,000 parts.
- **Resume:** `GET /v1/uploads/{id}` returns the parts S3 already has (via ListParts) plus fresh URLs.
- Completion is **idempotent**: an `Idempotency-Key` header plus a conditional state update. It emits `VideoUploaded` through the outbox in the same transaction.
- Abandoned uploads:
  - a scheduler sweeper runs every 15 min;
  - an S3 lifecycle rule aborts incomplete multipart uploads after 7 days.
- S3 `ObjectCreated` events → SQS → a reconciliation job completes sessions whose client never called complete.

## Alternatives considered
- **Proxy through the API:** simple client, but expensive and fragile.
- **tus protocol server:** a standard protocol, but the bytes still pass through our servers.

## Consequences
- ➕ API servers never handle video bytes, and upload capacity is effectively unlimited.
- ➖ More client-side logic (chunking, retries, resume). We'll publish a client upload guide with retry/backoff rules.
- The bucket needs CORS configured for the web origin, with `ETag` exposed.

## Revisit when
We need uploads from regions far from ours (consider S3 Transfer Acceleration or regional buckets).
