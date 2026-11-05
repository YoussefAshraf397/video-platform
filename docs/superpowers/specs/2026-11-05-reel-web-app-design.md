# reel web app: design

Date: 2026-11-05. Status: draft for review.

## Intent

reel is a general video platform, in the same category as YouTube but with its own look. The backend (Laravel API, Go media worker, S3/SNS/SQS through Floci locally) already covers accounts, direct-to-S3 uploads, processing, playback with signed cookies and captions. There is no client for it except the internal test player (S4-07, `/dev/player`). This project builds the real web app that viewers and creators use, so the backend can be exercised end to end through a product, not Postman.

Two kinds of people use it:

- **Viewers** browse and watch. They don't need an account.
- **Creators** sign up, upload videos, watch them process, edit details, add captions, and publish.

## Goals

- A viewer can land on the home page, find a recent video, and watch it with captions and quality switching.
- A viewer can open a creator's profile and see their public videos.
- A creator can sign up, verify their email, upload a large file (resumable across a closed tab or a dropped network), follow processing, edit metadata, add and remove captions, and publish or unpublish.
- The app has the "Marquee" visual identity (below) and doesn't read as a YouTube clone or a generic template.
- Works from phone width up to wide desktop.

## Non-goals (for this project)

- Comments, reactions, subscriptions, watch history, notifications, search. They're scheduled in later sprints and the backend doesn't have them.
- The admin processing-jobs screen. Admins keep using Postman.
- Choosing or uploading a custom thumbnail (FR-13 in the system design). The backend has no endpoint yet.
- Arabic or any other language, and right-to-left layout. English only. Plain strings in components are fine; no i18n library.
- A light theme. Marquee is dark by design.
- Server-side rendering and SEO.

## Anti-goals

- Looking like YouTube (red accent, left rail of icons, white grid of identical cards).
- A generic component-kit look (Material, shadcn defaults, rounded cards with grey shadows everywhere).
- Losing an upload because the tab closed or the network dropped. The backend supports resume; the app must use it.
- Storing the refresh token where scripts can read it. It stays in the HttpOnly cookie the API sets.

## Constraints

- **Repo layout:** the app lives in a new top-level `web/` folder, next to `api/`, `media-worker/` and `contracts/`.
- **Origin:** dev server on `http://localhost:3000`. The API's CORS (`FRONTEND_ORIGINS`), the refresh cookie and the media bucket's CORS already allow this origin. Email links already point at `FRONTEND_URL` + `/verify-email?token=` and `/reset-password?token=`, so those two routes must exist with those paths.
- **API:** `http://localhost:8000/v1`, errors as RFC 7807 problem details with a `code`, cursor pagination (`CursorPage`), `If-Match`/ETag optimistic concurrency on video edits, `Idempotency-Key` on creates. `api/openapi.json` is the source of truth for shapes.
- **Auth:** login returns an access token in the body (15-minute lifetime) and sets the refresh token as an HttpOnly, SameSite=Strict cookie scoped to `/v1/auth`. `POST /v1/auth/refresh` with `credentials: 'include'` rotates both. Another tab may refresh at the same moment; the API tolerates that.
- **Playback:** `GET /v1/videos/{id}/playback` returns the HLS URL, sets signed cookies scoped to the media path, and lists caption tracks. hls.js must send credentials (`xhrSetup` → `withCredentials`). The player re-calls playback on a 403 from the media origin and shortly before `auth.expires_at`. The test player (`api/app/Modules/Playback/resources/views/test-player.blade.php`) already does all of this correctly and is the reference.
- **Uploads:** follow `docs/CLIENT_UPLOAD_GUIDE.md` exactly (part size from the API, 3–6 parts in parallel, retry with jitter, resume via `GET /v1/uploads/{id}`, refresh part URLs when they expire, captions through the POST form upload). `docs/examples/upload-client.mjs` is a working reference.
- **Local only.** Never create real AWS resources.

## Decided: how it's built

These were the builder's calls (made in conversation, accepted by the owner):

- **React + TypeScript + Vite**, a client-rendered single-page app.
- **Types generated from `api/openapi.json`** (openapi-typescript), with a thin typed fetch wrapper. Regenerate when the API changes.
- **TanStack Query** for server state, including polling the processing endpoint while a video is processing.
- **React Router** for routing.
- **CSS Modules plus a global tokens file** (CSS custom properties) for styling. No Tailwind, no component kit.
- **hls.js** for playback.

## Decided: backend additions

The viewer side has nothing to browse today, so the project adds two read endpoints to the Videos module. They're public, need no account, and return only videos that are `published` with visibility `public`, and are **not age-restricted** (my call: Playback refuses age-restricted videos to everyone but the owner until age verification exists, so listing them would show tiles nobody can play).

1. **Latest videos:** `GET /v1/videos?category={slug}`, newest `published_at` first, cursor-paginated. `category` is optional (my call, because the categories endpoint already exists and the nav has a Categories entry).
2. **A creator's videos:** `GET /v1/users/{handle}/videos`, the same shape and order.

Each item carries what a video tile needs: `id`, `title`, `duration_ms`, `published_at`, a `creator` object (`handle`, `display_name`), and `thumbnails`: the WebP files as a list of `{url, width, height}` sorted by width, for `srcset` (empty if there isn't one yet).

**Thumbnail access (my call).** Thumbnails sit under each video's output prefix in the private `media` bucket, which only signed cookies scoped to one video can read. A grid can't hold cookies for 24 videos at once, so the listings return a short-lived **signed URL per thumbnail**. Locally that's an S3 presigned GET. In AWS it's a CloudFront signed URL from the same signer the playback cookies use. Copying thumbnails to the public `images` bucket is the alternative and was rejected for now, because it adds a copy step to processing.

**Handles at sign-up (owner's decision).** Today `POST /v1/auth/register` leaves `users.handle` null, and a handle can only be set later with `PATCH /v1/me`. The design depends on every creator having one (profile URL, tile links, "More from…"). So registration generates a handle from the display name: lowercased, transliterated to ASCII, cut to fit `Accounts::HANDLE_PATTERN`, with a numeric suffix if it's taken (unique under concurrent sign-ups, relying on the existing unique index and retrying on conflict). If nothing usable is left (for example, a name entirely in a non-Latin script), it falls back to `user-` plus a short random suffix. Creators can change it in settings. Existing local users without a handle get one from a one-off backfill in the same migration. Because every creator has a handle, `creator.handle` is never null in responses.

**Related response gaps** the app needs. These are small additions to existing responses:

- `GET /v1/videos/{id}` should include `creator` (`handle`, `display_name`) and `duration_ms`, so the watch page can show who made the video without a second call.
- `GET /v1/me/videos` items should include `thumbnails` (same shape as above), `duration_ms`, and `caption_languages` (the languages of the video's live subtitle tracks, from one grouped query for the page), so the studio list has images and its captions column without a call per row (my call).

Every addition follows the module rules (Contracts/Events only across modules, architecture tests), gets Pest tests, is reflected in `api/openapi.json`, and gets requests in the Postman collection, like every other ticket.

## Pages

Viewer side, no account needed:

| Route | Page |
|---|---|
| `/` | Home: the newest public video featured large (title, creator, "Watch now"), then a grid of the latest videos, loading more on scroll or with a "More" button. |
| `/categories/:slug` | The latest videos in one category. The category list comes from `GET /v1/categories`. |
| `/watch/:videoId` | Player (captions picker, quality picker, fullscreen), title, creator (links to profile), publish date, description, tags, category. Alongside it: "More from {creator}", from the creator's videos endpoint. |
| `/@:handle` | Profile: display name, handle, bio, joined date, grid of public videos. |

Account pages:

| Route | Page |
|---|---|
| `/sign-up`, `/sign-in`, `/forgot-password` | Forms. |
| `/verify-email?token=` | Exact path, from the email link. |
| `/reset-password?token=` | Exact path, from the email link. |
| `/settings` | Profile (display name, handle, bio via `PATCH /v1/me`) and "where you're signed in" (sessions list, sign out one, sign out everywhere). |

Creator side, signed-in creators only:

| Route | Page |
|---|---|
| `/studio` | The creator's videos (all statuses), with thumbnail, title, visibility, duration, a plain-language status and caption languages. Any upload in progress in this browser is pinned at the top with progress and pause/resume. |
| `/studio/upload` | Pick a file → create the video (title defaults to the file name) → upload starts at once, and the creator fills in details while it uploads. |
| `/studio/videos/:videoId` | Edit details (title, description, tags, category, language, visibility, age-restricted, made for kids, comments enabled, publish when ready), processing progress, captions (add a file with language/kind/label, see each track's status and errors, delete), publish/unpublish, delete video. |

Video status shown to creators, in their words, mapped from the API's statuses (my call):

| API status | Shown as | What the creator can do |
|---|---|---|
| `draft`, `upload_pending`, `upload_failed` | Waiting for the file | Pick the file to start or resume the upload; delete |
| `uploading` | Uploading | Pause/resume (in this browser), or pick the file again in another browser to resume; delete |
| `uploaded`, `validating`, `queued_for_processing`, `processing` | Processing | Watch progress; edit details; add captions |
| `ready` | Ready to publish | Publish; edit; add captions |
| `published` | Published | Unpublish; edit; add captions |
| `unpublished` | Unpublished | Publish again; edit |
| `processing_failed` | Failed | Shown with the plain-language reason for the processing `code`. The remedy is always "Delete it and upload the file again"; when `retryable` is true the message adds that the problem was on our side, not in the file. Nothing retries automatically (only the admin retry endpoint does), so the app never promises a retry. No creator retry endpoint is added. |
| `rejected` | Couldn't be processed | Final. The reason, and "Delete it and upload a different file". |
| `blocked` | Blocked | Read-only, with the reason if the API gives one. |

Watching an unlisted video works by link. A private video plays only for its owner. The API already enforces both; the app shows the API's problem `detail` when playback is refused.

## Behaviour that matters

- **Session:** keep the access token in memory only. On load, try `POST /v1/auth/refresh` once to restore the session silently. On a 401 from any call, refresh once and retry; if refresh fails, treat the user as signed out. Concurrent 401s share one refresh.
- **Unverified email:** the API requires a verified email to upload. The studio shows a clear prompt to verify, with a "Resend email" button, instead of a failing upload.
- **Resumable upload:** persist the upload id, video id and file identity (name, size, last modified) in `localStorage`. After a reload, the studio offers to resume when the creator picks the same file again (browsers can't reopen a file on their own). Parts already done are skipped via `GET /v1/uploads/{id}`.
- **Leaving during upload:** warn with `beforeunload` while parts are in flight.
- **Edits:** send `If-Match` with the video's `etag`. On 412, reload the video and tell the creator someone else changed it (another tab), keeping their unsaved text visible.
- **Retries:** creates send an `Idempotency-Key` so a retried request doesn't make two videos or two caption tracks.
- **Processing:** poll `GET /v1/videos/{id}/processing` every few seconds while the status is processing, and stop when it settles.
- **Playback refresh:** as in the test player, on a 403 from media and before `auth.expires_at`.
- **Errors:** show the problem `title`/`detail` from the API, mapped to plain words for known `code`s. Validation errors (422) attach to their fields.

## Visual identity: Marquee

Chosen from three directions shown as mockups (`.superpowers/brainstorm/…/visual-direction.html` and `marquee-watch-studio.html`). A screening room at night: the video is the light source and the interface is the dark room around it.

Colour tokens:

| Token | Hex | Use |
|---|---|---|
| `--plum-900` | `#120D18` | Player stage, the darkest surface |
| `--plum-800` | `#1E1626` | Page background |
| `--plum-700` | `#2A2033` | Raised surfaces (upload panel, inputs, menus) |
| `--plum-600` | `#3A2E44` | Hairlines and borders |
| `--ivory` | `#F3E9DC` | Primary text |
| `--mauve` | `#A795A8` | Secondary text |
| `--amber` | `#FFB547` | Primary actions, progress, focus ring, logo |
| `--rose` | `#C98B9B` | Creator names and accents |
| `--ok` / `--warn` / `--bad` | `#8FD18B` / `#FFB547` / `#FF8A7A` | Status dots |

Type:

- **Bricolage Grotesque** (800 and 600) for the logo, headings and video titles, with tight negative tracking at large sizes.
- **Figtree** (400–600) for body text and UI.
- Both are self-hosted (fontsource), not loaded from Google at runtime.
- The type scale is roughly 13 / 15 / 20 / 32 / 44 px. Body line length stays under 70 characters.

Layout and principles:

- Video tiles sit flat on the background: a 16:9 image with an 8px radius, and the title and meta below it. No card boxes and no shadows on grid items.
- The only glow in the app is under the featured video and under the player, a soft amber light as if from a screen. That's the one memorable flourish; everything else stays quiet.
- Pill-shaped buttons. Amber is used for the one primary action per view; secondary actions are outlined.
- Studio rows are a ruled list (hairlines between rows), not cards, with a coloured dot for status.
- No all-caps labels, no decorative numbering, no arrow glyphs on links.
- Motion is limited to responses to actions (menus opening, progress, toasts). It respects `prefers-reduced-motion`.
- Accessibility: visible amber focus ring, keyboard-operable player controls and forms, AA contrast (ivory and mauve on plum both pass), and captions on by default when the video's language has a track (my call).

## Left to the builder

- Folder structure inside `web/`, component breakdown, and naming.
- Exact responsive breakpoints and grid column counts.
- Whether the player uses custom controls or styled native controls. It must still offer captions and quality pickers in Marquee style.
- Test tooling. Vitest and Testing Library for units are expected, plus at least one browser end-to-end path (sign in → upload a small fixture → processing → publish → watch with captions) against the local stack.
- How `make` targets and `docker/compose.yaml` start the web app. A plain `npm run dev` on port 3000 is acceptable to start with.
- Empty and loading states, written in the voice described above.
