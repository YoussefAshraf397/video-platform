# Playback module

`GET /v1/videos/{id}/playback` (design doc §13, S4-04): decides whether the caller may watch, and returns the stream with CloudFront **signed cookies** for it. Signed in or not. It owns no tables: it reads the Videos module through `VideoAccess` (the cached access policy) and Processing through `PlayableMedia` (the newest playable output).

## Who may watch

`Services\AccessRules`, a pure function of the video's policy and the viewer. "404" means the viewer can't learn the video exists.

| Video | Anonymous | Owner | Someone else |
|---|---|---|---|
| Published, `public` or `unlisted` (the link is the permission) | ✅ | ✅ | ✅ |
| Published, `private` | 404 | ✅ | 404 |
| `ready` (never published) or `unpublished`: owner preview | 404 | ✅ | 404 |
| Not playable yet (`draft`, uploading, processing, failed) | 404 | 409 `VIDEO_NOT_PLAYABLE` | 404 |
| `blocked` / `rejected`, or moderation-blocked | 404 | 403 `VIDEO_BLOCKED` | 404 |
| `deleted` / `purged` | 404 | 404 | 404 |
| Age-restricted (flag or moderation) | 403 `AGE_RESTRICTED` | ✅ | 403 `AGE_RESTRICTED` |

Age-restricted videos need a viewer with a verified age of 18+. Accounts have no age verification yet, so for now only the owner can play them.

The whole matrix is tested (`PlaybackTest`: 14 states × 3 viewers). Deliberate breakages (private playable by anyone, previews playable by anyone, missing cache invalidation) were all caught.

## Response

```json
{
  "video": {"id": "y4S2byb8ju0", "title": "Trip", "duration_ms": 125000, "width": 1920, "height": 1080},
  "streams": {"hls": "https://media.example.com/media/{video_id}/v1/master.m3u8"},
  "auth": {"type": "cookie", "expires_at": "…", "cookies": {"CloudFront-Policy": "…", "CloudFront-Signature": "…", "CloudFront-Key-Pair-Id": "…", "CloudFront-Hash-Algorithm": "SHA256"}},
  "thumbnails": {"primary": [{"url": "…/thumbs/50_1280x720.jpg", "width": 1280, "height": 720, "format": "jpg"}]},
  "captions": [{"language": "en", "label": "English", "kind": "captions", "url": "…/subs/{subtitle_id}.vtt"}],
  "resume_position_ms": null,
  "playback_session_id": "…"
}
```

`Cache-Control: no-store`: the response carries credentials.

## Media URLs for listings

`Contracts\MediaUrls::signedUrl(string $key): string` signs one media object (a thumbnail, say) for use in a JSON response, where signed cookies don't fit. `Services\SignedUrls` implements it, and the Videos module's listings use it.

- **Shape:** `{MEDIA_BASE_URL}/{key}?Expires=…&Signature=…&Key-Pair-Id=…`, a CloudFront **canned policy** URL signed with the same key pair as the cookies.
- **Expiry:** the next full hour plus one hour. Within one clock hour every call returns the same URL, so browsers and CDNs can cache the image, and each URL is valid for at least 60 minutes.
- **Unconfigured:** without `CLOUDFRONT_KEY_PAIR_ID` / `CLOUDFRONT_PRIVATE_KEY` it throws `503 PLAYBACK_UNAVAILABLE`, like the playback endpoint.
- Locally the S3 emulator ignores the signature.

## Test player (S4-07)

`GET /dev/player` is an internal page for demos and QA until the web app exists. It is only served when `PLAYBACK_TEST_PLAYER=true` (local and dev; the default is off, so production answers 404).

**Open:** `http://localhost:8000/dev/player?v=<video id>`. To have something to play, run the pipeline next to the API:

```bash
php artisan serve                               # or octane:start
php artisan outbox:relay
php artisan messages:consume media-dispatcher
php artisan messages:consume media-results
cd ../media-worker && AWS_ENDPOINT_URL=http://localhost:4566 AWS_ACCESS_KEY_ID=test AWS_SECRET_ACCESS_KEY=test AWS_REGION=us-east-1 go run ./cmd/media-worker
```

Upload a real video with [`docs/examples/upload-client.mjs`](../../../../docs/examples/upload-client.mjs), wait for `ready`, then publish it or sign in on the page to preview it.

**What the page does:**
- **Plays like a real client.** It calls `GET /v1/videos/{id}/playback` (same origin as the API), lets the response set the signed cookies, and plays `streams.hls` with hls.js. hls.js 1.7.4 is pinned and integrity-checked. Safari falls back to native HLS.
- **Sends the cookies.** Media is cross-origin, so segment requests go with credentials. The media origin's CORS must allow the page's origin with credentials; locally `docker/aws/init.sh` sets that on the `media` bucket.
- **Signs in optionally**, through `POST /v1/auth/login`. The token is kept in memory only. Anonymous viewers get public and unlisted published videos. Signed in, you can preview your own `ready` or `unpublished` videos. Refusals show the API's problem code (e.g. `404 VIDEO_NOT_FOUND`, `409 VIDEO_NOT_PLAYABLE`, `429` with `Retry-After`).
- **Toggles captions (S4-06):** a track picker fed by the subtitle tracks hls.js finds in the master playlist (the same ones the video's CC menu offers), plus the caption on screen right now.
- **Shows rendition switching:**
  - The rendition table lists resolution, bitrate, frame rate and codecs, and highlights the one playing.
  - "Auto" (adaptive), or force a rendition.
  - Stats: bandwidth estimate, buffer ahead and dropped frames.
  - An event log.
- **Refreshes cookies like a real player:** 2 minutes before `auth.expires_at`, and when the CDN answers `403`. It asks again at most every 10 s, so cookies that were refused while fresh don't cause a loop.

**Checked in a browser (Chromium) against the local stack:**
- Anonymous playback of a published video starts at 360p, and the adaptive logic moves it up to 720p.
- Forcing 360p decodes 640×360 frames from the next segment on, and "Auto" goes back up.
- The browser sends all four cookies under this video version's path only: not for another video or another `v{n}`. They aren't readable from JavaScript.
- A simulated CDN `403` triggers a refresh, and playback continues.
- An unpublished video gives `404` anonymously and plays for its signed-in owner.
- No horizontal scroll at phone width.

Locally the emulator doesn't check signatures, so wrong cookies would still play. Checking them needs CloudFront (S4-03).

## Signed cookies

- **One custom policy covers a whole processing version:** `https://media…/media/{video_id}/v{n}/*`, so it covers the master playlist, rendition playlists, segments and thumbnails. Manifests stay static and cacheable, and nothing is rewritten per viewer. Every video is signed, public ones included (§13.3: hotlinking, fast takedowns). The cache key ignores signatures, so caching is unaffected.
- **Lifetime:** the video's length plus 30 minutes, kept between 1 and 6 hours. While the length is still unknown (the first rendition is ready but processing hasn't finished), it's 1 hour. The player calls this endpoint again on a 403 from the CDN or shortly before `expires_at`. Unpublishing, making a video private or blocking it stops new cookies at once, and existing ones run out within their lifetime (§12.4). Urgent legal takedowns also remove the origin objects.
- **Signing:** RSA with SHA-256 (`CloudFront-Hash-Algorithm`), using the AWS SDK's `CookieSigner`. A test verifies the signature against the public key and checks the decoded policy (resource and expiry).
- **The `Set-Cookie` headers:**
  - path `/media/{video_id}/v{n}/`, under `MEDIA_BASE_URL`'s own path if it has one (locally `/media/media/{video_id}/v{n}/`, the emulator's bucket path), so the browser sends them with every request for that version and no other;
  - `Domain` = `MEDIA_COOKIE_DOMAIN` (the parent, e.g. `.example.com`, so cookies set by `api.` reach `media.`);
  - `HttpOnly`, `SameSite=Lax` (the web app and media domain are same-site), and `Secure` over HTTPS.

  The same values are in `auth.cookies` for native players that send them as headers.

**Keys:** `php artisan playback:cdn-keys` prints a new RSA pair.
- The public key goes in the distribution's trusted key group (S4-03).
- The private key goes, base64-encoded, into `CLOUDFRONT_PRIVATE_KEY` (Secrets Manager in AWS), with `CLOUDFRONT_KEY_PAIR_ID`.
- Rotate by adding the new key to the group first, then switching the id.
- Without a key the endpoint answers `503 PLAYBACK_UNAVAILABLE` instead of failing.

Locally, `MEDIA_BASE_URL` is the S3 emulator, which ignores signatures.

## Policy cache

The Videos module caches each video's access policy (`vpolicy:{public_id}`, 5 min plus jitter, stored as a plain array). Every write that can change who may watch clears it, once at once and once after commit:
- state transitions (publish, unpublish, block, delete…);
- metadata edits (visibility, age restriction, title);
- the visibility set when publishing;
- processing results (duration, primary thumbnail).

Tests prove the cache is really used (a change made behind the application's back isn't seen) and that each kind of change applies on the very next request.

The newest playable output is one indexed query on `video_processing_jobs`, not cached.

## Performance

Local baseline on Octane (8 workers): 3,000 requests at 20 concurrent, half anonymous and half signed in, with no errors. Results: **p50 8.9 ms, p95 12.1 ms, p99 15.3 ms**. The acceptance target (p95 < 150 ms) is to be confirmed with the same script in dev once it runs in AWS (S2-01 / S4-03).

## Not yet built

- The CloudFront distribution, OAC and trusted key group (S4-03). Until then, cookies are signed correctly but nothing checks them. Its response headers policy must also allow the web app's and the test player's origins with credentials (CORS), as the local `media` bucket does.
- Age verification for viewers, region policies and embed restrictions (GROWTH), and moderator preview of blocked videos (admin console).
- Resume position (history) and the analytics beacon URL. (Rate limits: see the `playback` limiter in [Platform/Api](../../Platform/Api/README.md#rate-limits-design-doc-243).)
- Cookie-less clients (some TVs, casting receivers) need tokenised URLs at the edge (GROWTH).
