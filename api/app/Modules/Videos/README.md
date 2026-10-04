# Videos module

Owns video metadata and, from S3-02, the video state machine (design doc §12): the `videos`, `categories`, `tags` and `video_tags` tables.

## Endpoints

| Endpoint | Auth | Notes |
|---|---|---|
| `POST /v1/videos` | Bearer + `Idempotency-Key` | Creates a `draft`, `private` by default. `201` with `Location` and `ETag`. |
| `GET /v1/videos/{id}` | Optional | Owners see their own videos in any status. Everyone else sees only published `public`/`unlisted` videos that aren't blocked. Anything else is `404 VIDEO_NOT_FOUND`, so private videos don't reveal they exist. |
| `PATCH /v1/videos/{id}` | Bearer + **`If-Match`** | Owner only. Send only the fields you're changing. `tags` replaces the whole list. `null` clears `description`, `tags`, `category` and `language`. |
| `DELETE /v1/videos/{id}` | Bearer | Owner only, soft delete (status `deleted`). `If-Match` is optional and honoured if sent. `204`. |
| `GET /v1/me/videos` | Bearer | Your videos in every status except deleted, newest first, cursor-paginated. |
| `GET /v1/categories` | — | Active categories in display order: `[{slug, name}]`. |

`{id}` is the 11-character `public_id` (random base64url). The internal UUID never leaves the API.

Editable fields: `title` (1–100, no control characters), `description` (≤ 5000), `tags` (≤ 30, each ≤ 50 characters), `category` (slug), `language` (BCP 47, e.g. `en-GB`), `visibility` (`public` | `unlisted` | `private`), `age_restricted`, `made_for_kids`, `comments_enabled` (JSON booleans). Any other field, such as `status`, is `422 UNKNOWN_FIELD`.

Who can change what: owner-only writes return `404` when the caller can't see the video and `403 NOT_VIDEO_OWNER` when they can (it's published) but it isn't theirs.

## Concurrent edits (ETag / If-Match)

Every response carries `ETag: "<state_version>"`, also as `etag` in the body, so list items can be edited directly. Every write bumps the version with a conditional `UPDATE … WHERE state_version = <If-Match>`:

- Missing `If-Match` on PATCH: `428 PRECONDITION_REQUIRED`.
- Stale or unrecognised `If-Match` (including `*` and weak tags): `412 PRECONDITION_FAILED`, and nothing changes. Clients should re-fetch, reapply the user's change and retry.

Verified against a real server: 10 simultaneous PATCHes with the same ETag gave one `200` and nine `412`s, and the stored title and tags came from the single winner.

`state_version` is the version for **all** writes, metadata and status alike. The S3-02 transition service also checks the expected status, so a status change that loses a race with a metadata edit can re-read and retry safely.

## Tags

Matched case-, Unicode- and whitespace-insensitively (NFKC, collapsed whitespace, lowercase): `Lo-Fi  Beats`, `lo-fi beats` and `ＬＯ-ＦＩ BEATS` are one tag in `tags`. The creator's spelling and order are kept per video in `video_tags.label` / `position`. Duplicates within one video collapse to the first spelling.

## Categories

Seeded by the migration, not a seeder, so every environment has the same ids and slugs. Deactivate with `is_active = false` rather than deleting, because videos reference them.

## Not yet built

- Ownership is `uploader_user_id` until the Channels module (S2-06) adds `channel_id`, and the policy layer (S2-07) replaces the owner check in `Services\Videos::findOwned`.
- Status changes and their outbox events (`VideoStateChanged`, `VideoMetadataUpdated`): S3-02. Today `DELETE` sets `deleted` directly; S3-02 moves it into the transition service.
- Publishing, so nothing is `published` yet outside tests.
- Purge after the 30-day grace period, caching (`video:{id}:v{state_version}`) and the search index.
- Limits on draft creation per user (upload quotas arrive with S3-03).
