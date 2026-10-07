# Videos module

Owns video metadata and the video state machine (design doc §12): the `videos`, `categories`, `tags` and `video_tags` tables. Other modules use `Contracts\VideoDirectory` (owner lookups), `Contracts\VideoLifecycle` / `Contracts\VideoStatus`, and listen to `Events\VideoStateChanged`.

## Endpoints

| Endpoint | Auth | Notes |
|---|---|---|
| `POST /v1/videos` | Bearer + `Idempotency-Key` | Creates a `draft`, `private` by default. `201` with `Location` and `ETag`. |
| `GET /v1/videos/{id}` | Optional | Owners see their own videos in any status. Everyone else sees only published `public`/`unlisted` videos that aren't blocked. Anything else is `404 VIDEO_NOT_FOUND`, so private videos don't reveal they exist. |
| `PATCH /v1/videos/{id}` | Bearer + **`If-Match`** | Owner only. Send only the fields you're changing. `tags` replaces the whole list. `null` clears `description`, `tags`, `category` and `language`. |
| `DELETE /v1/videos/{id}` | Bearer | Owner only, soft delete (status `deleted`). `If-Match` is optional and honoured if sent. `204`. |
| `POST /v1/videos/{id}:publish` | Bearer | Owner only. Optional body `{visibility}` sets it in the same step. See [Publishing](#publishing). |
| `POST /v1/videos/{id}:unpublish` | Bearer | Owner only. The video is kept but only its owner sees it until republished. |
| `GET /v1/me/videos` | Bearer | Your videos in every status except deleted, newest first, cursor-paginated. |
| `GET /v1/categories` | — | Active categories in display order: `[{slug, name}]`. |

`{id}` is the 11-character `public_id` (random base64url). The internal UUID never leaves the API.

Editable fields: `publish_on_ready` (JSON boolean), `title` (1–100, no control characters), `description` (≤ 5000), `tags` (≤ 30, each ≤ 50 characters), `category` (slug), `language` (BCP 47, e.g. `en-GB`), `visibility` (`public` | `unlisted` | `private`), `age_restricted`, `made_for_kids`, `comments_enabled` (JSON booleans). Any other field, such as `status`, is `422 UNKNOWN_FIELD`.

Who can change what: owner-only writes return `404` when the caller can't see the video and `403 NOT_VIDEO_OWNER` when they can (it's published) but it isn't theirs.

## Concurrent edits (ETag / If-Match)

Every response carries `ETag: "<state_version>"`, also as `etag` in the body, so list items can be edited directly. Every write bumps the version with a conditional `UPDATE … WHERE state_version = <If-Match>`:

- Missing `If-Match` on PATCH: `428 PRECONDITION_REQUIRED`.
- Stale or unrecognised `If-Match` (including `*` and weak tags): `412 PRECONDITION_FAILED`, and nothing changes. Clients should re-fetch, reapply the user's change and retry.

Verified against a real server: 10 simultaneous PATCHes with the same ETag gave one `200` and nine `412`s, and the stored title and tags came from the single winner.

`state_version` is the version for **all** writes, metadata and status alike.

## State machine

`VideoLifecycle::transition($videoId, VideoStatus::X, 'reason', ?$expectedVersion)` is the only way to change `status`. The legal moves are in `Contracts\VideoStatus::next()`, which follows the design doc §12.3:

```
draft → upload_pending → uploading → uploaded → validating → queued_for_processing → processing → ready → published ⇄ unpublished
upload_pending / uploading → upload_failed → upload_pending          validating → rejected | processing_failed
processing → processing_failed → queued_for_processing
any live state → blocked → (the state it was blocked from) | deleted
anything but deleted/purged → deleted → purged
```

Each transition, in one transaction:

1. Reads the row and checks the move is legal. An illegal move throws `IllegalVideoTransition`, logs `video.transition_rejected` at warning level, and changes nothing.
2. Runs a conditional `UPDATE … WHERE status = :read_status AND state_version = :read_version`. If the row changed in between (an edit, another transition), it re-reads, re-checks and retries (up to 5 times). With `$expectedVersion` (If-Match) it returns `412` instead.
3. Publishes **exactly one** outbox event (topic `video-events`, contract `video-state-changed.v1`) with `from`, `to`, `reason` and the new version as `aggregate_version`.

Event types: `VideoUploaded`, `VideoPublished`, `VideoUnpublished`, `VideoBlocked` and `VideoDeleted` for the moves other modules react to, so SQS subscriptions can filter on the `event_type` attribute. Every other move is `VideoStateChanged`. The payload is the same for all of them.

Side effects: the first publish sets `published_at`, and republishing keeps it. A block stores `pre_block_status` and `blocked_reason`, and lifting it returns to exactly that state. A delete sets `deleted_at`. A database `CHECK` constraint rejects any status not in the enum.

**Callers should expect `IllegalVideoTransition`** when racing: two concurrent upload completions give one success (and one `VideoUploaded`) and one `IllegalVideoTransition`. A processing result for a video that was deleted or blocked meanwhile is rejected the same way, so it doesn't change the state (§12.3).

Proof: every one of the 16×16 status pairs is checked against the spec table, which is written out separately in the test. Seeded random walks of 150 steps mixing transitions and edits check that the events form one unbroken path with one event per accepted move. Six deliberate breakages were all caught. In a real run, 12 processes racing `uploading → uploaded` while 8 others edited the row gave 1 success, 11 `IllegalVideoTransition`s and 1 event.

## Publishing

`Services\Publication`, the only place publication is decided (design doc §12.4).

- **Guards**, every unmet one listed in `409 NOT_PUBLISHABLE`:
  - `NOT_READY`: processing hasn't made the video `ready` (or it isn't `unpublished`, for republishing);
  - `TITLE_REQUIRED`;
  - `BLOCKED` by moderation.

  Guards on moderation pre-review and age/region (GROWTH) slot in here.
- **Visibility** (`public` | `unlisted` | `private`) can be set in the publish call, atomically with it, or changed later with `PATCH`. Changing it on a published video doesn't publish it again.
- Publishing a published video and unpublishing an unpublished one are no-ops. `If-Match` is optional and honoured.
- Each transition emits `VideoPublished` / `VideoUnpublished` via the state machine. Republishing keeps the first `published_at`.
- **`publish_on_ready`**: when processing moves a video to `ready`, the state machine dispatches an in-process `VideoTransitioned` event, inside the same transaction, and `Publication::publishOnReady` publishes it.
  - READY and PUBLISHED commit together, so nobody ever sees a READY video that should have been published.
  - If a guard fails, the video simply stays `ready`, and the log says which guards.
  - The flag only matters at that moment: setting it on a video that's already `ready` doesn't publish it.

## Media and thumbnails

Processing records what it found and made through `Contracts\VideoMedia::recordProcessed`:

- `duration_ms`, `source_width` / `source_height` (display size), and the `processing_version` they came from. An older version never overwrites a newer one.
- `thumbnails`: one row per candidate frame (`time_offset_ms`), with its files (sizes × JPEG/WebP) in `files`.
- The candidate nearest the middle of the video becomes primary. It replaces an auto primary from an older version, but never a custom one. There is one primary per video (a partial unique index).

## Tags

Matched case-, Unicode- and whitespace-insensitively (NFKC, collapsed whitespace, lowercase): `Lo-Fi  Beats`, `lo-fi beats` and `ＬＯ-ＦＩ BEATS` are one tag in `tags`. The creator's spelling and order are kept per video in `video_tags.label` / `position`. Duplicates within one video collapse to the first spelling.

## Categories

Seeded by the migration, not a seeder, so every environment has the same ids and slugs. Deactivate with `is_active = false` rather than deleting, because videos reference them.

## Not yet built

- Ownership is `uploader_user_id` until the Channels module (S2-06) adds `channel_id`, and the policy layer (S2-07) replaces the owner check in `Services\Videos::findOwned`.
- `VideoMetadataUpdated` events for cache and search invalidation (with caching / search).
- `scheduled` (GROWTH), undoing a delete within the grace period, and blocking an already deleted video (legal holds are handled separately).
- Purge after the 30-day grace period, caching (`video:{id}:v{state_version}`) and the search index.
- Limits on draft creation per user (upload quotas arrive with S3-03).
