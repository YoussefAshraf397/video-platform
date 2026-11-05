# reel Web App Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the reel web app (viewers browse and watch; creators upload, process, caption and publish), plus the backend additions it needs.

**Architecture:**
- **Backend.** Four small additions inside the existing Laravel modular monolith:
  - handles generated at registration;
  - a Playback-owned signed-URL contract for thumbnails;
  - two public listing endpoints in the Videos module;
  - extra fields on existing video responses.
- **Frontend.** A client-rendered React SPA in a new top-level `web/`, on `localhost:3000`, talking to `localhost:8000/v1`.
  - API types are generated from `api/openapi.json`.
  - Server state goes through TanStack Query.
  - Uploads follow `docs/CLIENT_UPLOAD_GUIDE.md`.
  - Playback reuses the test player's proven hls.js setup.

**Tech Stack:**
- Backend: Laravel 12 / Pest / PHPStan / Pint.
- Frontend:
  - Core: React 19, TypeScript, Vite.
  - Routing and data: React Router 7, TanStack Query 5.
  - API types: openapi-typescript.
  - Playback: hls.js 1.7.x.
  - Styling and fonts: CSS Modules, @fontsource (Bricolage Grotesque, Figtree).
  - Tests: Vitest, Testing Library and MSW 2 for unit tests; Playwright for end-to-end.

**Spec:** `docs/superpowers/specs/2026-11-05-reel-web-app-design.md`. Read it before any task. The tables of pages, statuses and colour tokens live there and are not repeated here.

## Global Constraints

**Backend**
- Modules use each other only through `Contracts/` and `Events/` (enforced by `tests/Architecture/ModuleBoundariesTest.php`).
- Every backend task must leave these green: `make test-api`, `vendor/bin/pint --test` and `vendor/bin/phpstan analyse`.
- Public listings include only videos with `status = 'published'`, `visibility = 'public'`, `age_restricted = false` and `deleted_at IS NULL`.
- Handles must match `Accounts::HANDLE_PATTERN`: 3–30 characters of letters, digits, `_` and `.`; starting with a letter or digit; no `..`; no trailing `.`.
  - **Deviation from the spec's example:** a hyphen isn't allowed, so the generated form is `mona.haddad`, not `mona-haddad`.
- Pagination shape: `{items, next_cursor, has_more}` via `App\Platform\Api\Pagination\CursorPage`.
- **Thumbnail shape (deviation from the spec):** the spec gives `thumbnail` as a single `{url, width, height}` or `null`. This plan returns `thumbnails: list<{url, width, height}>` instead:
  - only the WebP files;
  - sorted by width, ascending;
  - `[]` when there are none.

  The reason: the same field then feeds `<img srcset>` for both the small grid tiles and the large featured hero.
- Never create real AWS resources. Everything runs against the local stack (`make up`).

**Frontend**
- The web app lives in `web/` and serves on `http://localhost:3000`. The API base comes from `VITE_API_URL`, default `http://localhost:8000`.
- Every request uses `credentials: 'include'`. The access token is held in memory only, never in `localStorage`.
- The exact paths `/verify-email?token=` and `/reset-password?token=` must exist, because the API's emails link to them.
- English only. Copy rules:
  - sentence case and plain verbs;
  - no all-caps labels;
  - no `→` on links;
  - errors explain what happened and what to do, without apologising.
- Colour, type and principles come from the spec's "Visual identity: Marquee" section, using exactly its tokens:
  - Fonts are self-hosted through @fontsource, never loaded from Google at runtime.
  - Visible amber focus ring on everything focusable.
  - `prefers-reduced-motion` is respected.
  - Layouts work from 360px wide up.

**Process**
- No commits without the owner's say-so. The Commit steps below mean "stage with `git add`; the owner commits".

## Review Focus

1. **A resumed upload where S3 already has parts the browser doesn't know about.** The app must take ETags from `uploaded_parts` and never re-send or mis-list them in `:complete`. Covered by a test in Task 11.
2. **Two tabs (or many parallel queries) hitting 401 at once.** Exactly one `/v1/auth/refresh` call must run, and every waiter retries with the new token. Covered in Task 7.
3. **A display name with no Latin letters (`"محمد"`, `"李"`), or one shorter than 3 characters (`"Al"`).** Registration must still produce a valid, unique handle. Covered in Task 1.
4. **Signed thumbnail URLs that change on every request**, which would defeat browser caching across pages. The expiry is rounded to the hour, so the same thumbnail gets the same URL within an hour. Covered in Task 2.
5. **Editing a video in two tabs.** The 412 path must keep the creator's unsaved text and show the fresh server values. Covered in Task 13.

---

## File structure

Backend (in `api/`):

| Path | Responsibility |
|---|---|
| `app/Modules/Users/Services/Handles.php` (new) | Turns a display name into handle candidates. Pure; no DB. |
| `app/Modules/Users/Services/EloquentUserDirectory.php` | `create()` assigns a unique handle; new `findByHandle()` and `findMany()`. |
| `app/Modules/Users/Contracts/UserDirectory.php`, `UserRecord.php` | The contract additions; `handle` becomes non-null. |
| `app/Modules/Users/Database/Migrations/2026_11_06_000001_backfill_user_handles.php` (new) | Fills missing handles, then sets `handle` to NOT NULL. |
| `app/Modules/Playback/Contracts/MediaUrls.php` (new), `Services/SignedUrls.php` (new) | Signed GET URLs for single media objects. |
| `app/Modules/Videos/Services/VideoTiles.php` (new) | Presents a page of videos: creator, duration, thumbnails, with batched lookups. |
| `app/Modules/Videos/Http/Controllers/PublicVideosController.php` (new) | `GET /v1/videos` and `GET /v1/users/{handle}/videos`. |
| `app/Modules/Videos/Database/Migrations/2026_11_06_000002_add_public_listing_index_to_videos.php` (new) | Partial index for the listings. |
| `app/Modules/Videos/Http/Controllers/VideoController.php` | `present()` adds `creator` and `duration_ms`; `mine()` adds `thumbnails` and `caption_languages`. |

Frontend (in `web/`):

| Path | Responsibility |
|---|---|
| `src/styles/tokens.css`, `src/styles/global.css` | Marquee tokens, fonts, base elements and the focus ring. |
| `src/api/schema.d.ts` (generated), `src/api/client.ts` | Typed fetch, problem-details errors, auth header, retry once on 401. |
| `src/auth/session.ts`, `src/auth/SessionProvider.tsx` | In-memory token, single-flight refresh, and the current user. |
| `src/ui/*` | Small shared pieces: `Button`, `Field`, `ProblemMessage`, `VideoTile`, `VideoGrid`, `StatusDot`. |
| `src/layout/AppShell.tsx` | Top nav and the page frame. |
| `src/pages/viewer/*` | `Home`, `Category`, `Watch`, `Profile`. |
| `src/pages/account/*` | `SignUp`, `SignIn`, `ForgotPassword`, `VerifyEmail`, `ResetPassword`, `Settings`. |
| `src/player/*` | `Player.tsx`, `usePlayback.ts`, `refresh.ts`. |
| `src/studio/upload/*` | `uploader.ts` (the engine), `store.ts` (persistence), `useUploads.ts`. |
| `src/studio/*` | `StudioList`, `UploadPage`, `VideoEditor`, `CaptionsPanel`, `status.ts`. |
| `e2e/creator-to-viewer.spec.ts` | One Playwright path through the whole product. |

---

### Task 1: Handles generated at registration

**Files:**
- Create: `api/app/Modules/Users/Services/Handles.php`
- Create: `api/app/Modules/Users/Database/Migrations/2026_11_06_000001_backfill_user_handles.php`
- Modify:
  - `api/app/Modules/Users/Services/EloquentUserDirectory.php`
  - `api/app/Modules/Users/Contracts/UserDirectory.php`
  - `api/app/Modules/Users/Contracts/UserRecord.php`
  - `api/app/Modules/Users/Database/Factories/UserFactory.php`
- Test:
  - `api/tests/Unit/Users/HandlesTest.php`
  - `api/tests/Feature/Modules/Auth/RegistrationTest.php` (add cases)
  - `api/tests/Feature/Modules/Users/HandleBackfillTest.php`

**Interfaces:**
- Produces:
  - `Handles::candidates(string $displayName): \Generator<string>`
    - Yields the base form first.
    - Then yields `base.2`, `base.3`, … up to `base.9`.
    - After that, `base.` plus 4 random digits, without end.
    - Every value it yields matches `HANDLE_PATTERN`.
  - `UserDirectory::findByHandle(string $handle): ?UserRecord`, case-insensitive and active users only.
  - `UserDirectory::findMany(list<string> $ids): array<string, UserRecord>`, keyed by id.
  - `UserRecord::$handle` is `string`, no longer `?string`.

- [ ] **Step 1: Write failing unit tests** in `HandlesTest.php`:
  - `first(candidates('Mona Haddad')) === 'mona.haddad'`
  - `'Zoë Ångström'` gives `'zoe.angstrom'`
  - `'  ...Al!! '` gives `'al.user'` (pad to the 3-character minimum by appending `.user`)
  - `'محمد'` and `'李'`: the base matches `/^user\.\d{6}$/`
  - a 60-character name is cut to at most 30 characters, with no trailing `.`
  - the second and third candidates for `'Mona Haddad'` are `'mona.haddad.2'` and `'mona.haddad.3'`
  - the first 20 candidates all match `Accounts::HANDLE_PATTERN`
- [ ] **Step 2: Run** `php artisan test tests/Unit/Users/HandlesTest.php`. Expected: FAIL, class not found.
- [ ] **Step 3: Implement `Handles::candidates`.**
  - Use `Str::ascii`, then lowercase.
  - Turn each run of non-`[a-z0-9]` characters into a single `.`.
  - Trim dots from both ends.
  - Fall back to `user.` plus 6 random digits when the result is empty.
  - When adding a suffix, cut the base so that base plus suffix is at most 30 characters, then trim a trailing `.`.
- [ ] **Step 4: Run** the unit tests. Expected: PASS.
- [ ] **Step 5: Write failing feature tests** in `RegistrationTest.php`:
  - Registering `display_name: 'Mona Haddad'` stores handle `mona.haddad`.
  - A second account with the same name stores `mona.haddad.2`.
  - If a user already holds `MONA.HADDAD` (case differs), the new account gets `mona.haddad.2`.
  - Registering an email that already exists still creates no user, sends the "already exists" mail, and consumes no handle.
- [ ] **Step 6: Run** them. Expected: FAIL, handle is null.
- [ ] **Step 7: Change `EloquentUserDirectory::create`.** Loop over `Handles::candidates($displayName)`:
  - Skip candidates already taken (`whereRaw('lower(handle) = ?')`).
  - Insert with the existing `insertOrIgnore`, now including `handle`.
  - If the insert returns 0 and the email exists, return `null` as today.
  - If it returns 0 and the email doesn't exist, the handle lost a race: try the next candidate, up to 10 attempts, then throw `RuntimeException`.
  - Never let a unique violation throw: the caller is inside a transaction.

  Also add `findByHandle`, `findMany` and the contract methods, and make `UserRecord::$handle` a `string`.
- [ ] **Step 8: Write the failing backfill test** in `HandleBackfillTest.php`:
  - Insert two users with null `handle` and display name `'Ada'`.
  - Run the migration's `up()`.
  - Expect handles `ada` and `ada.2`, in `created_at` order.
  - Expect `users.handle` to be NOT NULL (`information_schema.columns.is_nullable = 'NO'`).
- [ ] **Step 9: Implement the migration.** In `created_at, id` order, give every null handle the first free candidate from `Handles::candidates`. Then run `ALTER TABLE users ALTER COLUMN handle SET NOT NULL`. `down()` drops NOT NULL only.
- [ ] **Step 10: Update `UserFactory`** so `handle` is `fake()->unique()->bothify('user_########')`.
- [ ] **Step 11: Run** `make test-api`, Pint and PHPStan. Expected: all green. `ProfileTest` and `MeTest` may assert a null handle; update those to the factory value.
- [ ] **Step 12: Stage** the files above.

---

### Task 2: Signed media URLs (Playback contract)

**Files:**
- Create:
  - `api/app/Modules/Playback/Contracts/MediaUrls.php`
  - `api/app/Modules/Playback/Services/SignedUrls.php`
- Modify: `api/app/Modules/Playback/Providers/PlaybackServiceProvider.php` (bind it)
- Test: `api/tests/Feature/Modules/Playback/SignedUrlsTest.php`

**Interfaces:**
- Produces:
  - `MediaUrls::signedUrl(string $key): string`
    - The URL is `{media_base_url}/{key}?Expires=…&Signature=…&Key-Pair-Id=…`.
    - It uses a CloudFront canned policy via `Aws\CloudFront\UrlSigner`, with the same `playback.key_pair_id` and `playback.private_key` as `SignedCookies`.
    - The same unconfigured check applies: `503 PLAYBACK_UNAVAILABLE`.
  - The expiry is the next full hour plus one hour, so URLs are stable within an hour and always valid for at least 60 minutes.

- [ ] **Step 1: Write failing tests:**
  - The URL starts with `config('playback.media_base_url').'/'.$key`.
  - The query has `Expires`, `Signature` and `Key-Pair-Id`.
  - Two calls 10 minutes apart within the same hour return identical URLs (`$this->travelTo(...)`).
  - `Expires - now()` is between 3600 and 7200.
  - Fetching the signed URL of a real object put into the local `media` bucket returns 200 (`Http::get`). The emulator ignores the signature; this proves the query string doesn't break the fetch.
  - With an empty key-pair id, the call throws `ApiProblem` with status 503.
- [ ] **Step 2: Run** them. Expected: FAIL.
- [ ] **Step 3: Implement** `SignedUrls` and bind `MediaUrls::class => SignedUrls::class`.
- [ ] **Step 4: Run** them, plus `make test-api` (the architecture test included). Expected: PASS.
- [ ] **Step 5: Stage.**

---

### Task 3: Public listings — latest videos and a creator's videos

**Files:**
- Create:
  - `api/app/Modules/Videos/Services/VideoTiles.php`
  - `api/app/Modules/Videos/Http/Controllers/PublicVideosController.php`
  - `api/app/Modules/Videos/Database/Migrations/2026_11_06_000002_add_public_listing_index_to_videos.php`
- Modify: `api/app/Modules/Videos/routes.php`
- Test: `api/tests/Feature/Modules/Videos/PublicListingTest.php`

**Interfaces:**
- Consumes:
  - `UserDirectory::findMany` and `UserDirectory::findByHandle` (Task 1)
  - `MediaUrls::signedUrl` (Task 2)
- Produces:
  - `VideoTiles::present(list<Video> $videos): list<array{id: string, title: string, duration_ms: ?int, published_at: ?string, creator: array{handle: string, display_name: string}, thumbnails: list<array{url: string, width: int, height: int}>}>`
    - Makes one query for the primary thumbnails' `files`.
    - Makes one `findMany` call for the creators.
  - `VideoTiles::thumbnailsFor(list<string> $videoIds): array<string, list<array{url,width,height}>>`, also used by Task 4.
  - Routes, public with no auth:
    - `GET /v1/videos?category={slug}&limit=&cursor=`
    - `GET /v1/users/{handle}/videos?limit=&cursor=`
    - Ordered by `published_at desc, id desc`.

- [ ] **Step 1: Write failing tests:**
  - **Listing rules:**
    - With one video in each state, only the published, public, non-age-restricted one is listed: not unlisted, private, ready, unpublished, deleted, age-restricted or blocked ones.
    - The order is newest `published_at` first.
    - `?limit=2` pages through 3 videos with `next_cursor`, with no repeats.
    - `?category=music` filters.
    - An unknown category gives `items: []`, not an error.
  - **Item shape:** `creator` equals `{handle, display_name}`; `duration_ms` matches.
  - **Thumbnails:**
    - The `thumbnails` URLs are the WebP files sorted by width, each signed (contains `Key-Pair-Id=`).
    - A video with no thumbnail row gives `thumbnails: []`.
  - **Profile listing:** `GET /v1/users/MONA.HADDAD/videos` matches case-insensitively. An unknown or suspended handle gives `404 USER_NOT_FOUND`.
  - **No access needed:** both routes work without an Authorization header.
  - **Query count:** stays constant for a page of 20 videos (`DB::enableQueryLog`; count ≤ 5 regardless of page size).
- [ ] **Step 2: Run** them. Expected: FAIL, 404 route.
- [ ] **Step 3: Write the migration.** Add a partial index `videos_public_listing_idx` on `(published_at DESC, id DESC) WHERE status = 'published' AND visibility = 'public' AND deleted_at IS NULL`.
- [ ] **Step 4: Implement `VideoTiles` and `PublicVideosController`** (`latest(Request)` and `byCreator(Request, string $handle)`) using `CursorPage::paginate`/`response` with the `VideoTiles::present` closure. Register the routes outside the `auth:api` group.
  - **Route clash:** `GET v1/videos` must not collide with `GET v1/videos/{video}`. The `{video}` pattern already requires 11 characters, so it doesn't.
- [ ] **Step 5: Run** the tests and `make test-api`. Expected: PASS.
- [ ] **Step 6: Stage.**

---

### Task 4: Extra fields on existing video responses

**Files:**
- Modify: `api/app/Modules/Videos/Http/Controllers/VideoController.php`
- Test: `api/tests/Feature/Modules/Videos/VideosTest.php` (add cases)

**Interfaces:**
- Consumes:
  - `UserDirectory::find`
  - `VideoTiles::thumbnailsFor`
- Produces:
  - **Every video response** (show, store, update, publish, unpublish, mine) gains:
    - `duration_ms: ?int`
    - `creator: {handle, display_name}`
  - **`GET /v1/me/videos` items** also gain:
    - `thumbnails`, in the Task 3 shape.
    - `caption_languages: list<string>`: the languages of tracks with status in `Subtitle::LIVE`, sorted, with no duplicates.
  - All of these are loaded with one grouped query per page.

- [ ] **Step 1: Write failing tests:**
  - `GET /v1/videos/{id}` includes `creator.handle` and `duration_ms`.
  - `GET /v1/me/videos` includes `thumbnails` and `caption_languages` (`['ar', 'en']` for a video with en and ar tracks plus one deleted fr track).
  - The query count for `me/videos` with 20 videos is ≤ 6.
- [ ] **Step 2: Run** them. Expected: FAIL.
- [ ] **Step 3: Implement.** `present()` takes the creator record. `mine()` batches the creators, thumbnails and caption languages.
- [ ] **Step 4: Run** `make test-api`, Pint and PHPStan. Expected: PASS.
- [ ] **Step 5: Stage.**

---

### Task 5: Backend docs, OpenAPI and Postman

**Files:**
- Modify:
  - `api/openapi.json` (via `make openapi`)
  - `api/app/Modules/Videos/README.md`
  - `api/app/Modules/Users/README.md`
  - `api/app/Modules/Playback/README.md`
  - `api/postman/video-platform.postman_collection.json`

- [ ] **Step 1:** Run `make openapi`. Check that `/v1/videos` (GET) and `/v1/users/{handle}/videos` appear, and that `thumbnails`, `creator` and `caption_languages` are in the schemas. If Scramble can't infer a closure's shape, add `@return` array shapes on the presenter methods until it can.
- [ ] **Step 2:** Update the READMEs:
  - the listing rules, and the age-restriction exclusion with the reason for it;
  - handle generation;
  - `MediaUrls`.
- [ ] **Step 3:** Add a "Browse" folder to the Postman collection:
  - **Latest videos**, with tests: 200, the `items` array, and the item keys.
  - **Latest by category.**
  - **Creator's videos**, by `{{handle}}`, set from `GET /v1/me`.
  - **Unknown creator**: 404 `USER_NOT_FOUND`.

  Also assert `creator`, `duration_ms`, `thumbnails` and `caption_languages` in the existing Get-video and My-videos requests.
- [ ] **Step 4:** Run the collection with the Postman CLI against the local stack:
  ```bash
  postman collection run api/postman/video-platform.postman_collection.json -e api/postman/video-platform.local.postman_environment.json
  ```
  Expected: 0 failures.
- [ ] **Step 5: Stage.**

---

### Task 6: Scaffold `web/` with the Marquee shell

**Files:**
- Create:
  - `web/package.json`, `web/vite.config.ts`, `web/tsconfig.json`, `web/index.html`, `web/.env.example`
  - `web/src/main.tsx`, `web/src/router.tsx`
  - `web/src/styles/tokens.css`, `web/src/styles/global.css`
  - `web/src/layout/AppShell.tsx` and `.module.css`
  - `web/src/ui/Button.tsx` and `.module.css`
  - `web/src/test/setup.ts`
  - `web/README.md`
- Modify:
  - `Makefile` (targets `web-install`, `web-dev`, `web-test`, `web-types`)
  - root `README.md` (how to run it)
  - root `.gitignore` (`web/node_modules`, `web/dist`, `.superpowers/`)
- Test: `web/src/layout/AppShell.test.tsx`

**Interfaces:**
- Produces:
  - **npm scripts:**
    - `dev`: Vite on port 3000, `strictPort`.
    - `build`, `test` (`vitest run`), `typecheck` (`tsc --noEmit`), `e2e` (Playwright).
    - `api:types`: `openapi-typescript ../api/openapi.json -o src/api/schema.d.ts`.
  - **`Button` props:** `{variant: 'primary' | 'secondary' | 'quiet', ...ButtonHTMLAttributes}`. Primary is the amber pill; secondary is outlined.
  - **`AppShell`:** renders the logo "reel" linking to `/`, plus Home and Categories (a menu fed by `/v1/categories` in Task 9). On the right:
    - signed out: "Sign in" and "Upload";
    - signed in: "Upload" and an avatar menu with Studio, Settings and "Sign out".

- [ ] **Step 1:** Scaffold the project with `npm create vite@latest web -- --template react-ts`. Add these dependencies:
  - `react-router`, `@tanstack/react-query`, `hls.js@1.7.4`
  - `@fontsource-variable/bricolage-grotesque`, `@fontsource-variable/figtree`
  - dev: `vitest`, `@testing-library/react`, `@testing-library/user-event`, `jsdom`, `msw`, `openapi-typescript`, `@playwright/test`

  Delete the template's demo files.
- [ ] **Step 2:** Write `tokens.css` with exactly the spec's colour tokens and type scale (13/15/20/32/44). `global.css` sets:
  - the `body` background `--plum-800` and text colour `--ivory`;
  - Figtree for body text, Bricolage for headings;
  - the focus ring `outline: 2px solid var(--amber); outline-offset: 2px` on `:focus-visible`;
  - a `prefers-reduced-motion` block that disables transitions.
- [ ] **Step 3: Write the failing test.** `AppShell` renders a link named "reel" pointing to `/`, a "Sign in" link when signed out, and a `main` landmark.
- [ ] **Step 4:** Implement `AppShell`, `Button` and `router.tsx` (placeholder routes for every path in the spec's tables), then run `npm test`. Expected: PASS.
- [ ] **Step 5:** Run `npm run api:types && npm run typecheck`. Expected: clean.
- [ ] **Step 6:** Run `npm run dev` and open `http://localhost:3000` in the browser pane. Check the shell matches the Marquee mockup's nav (`.superpowers/brainstorm/*/content/marquee-watch-studio.html`) at 1280px and at 375px.
- [ ] **Step 7: Stage.**

---

### Task 7: API client and session

**Files:**
- Create:
  - `web/src/api/client.ts`
  - `web/src/auth/session.ts`
  - `web/src/auth/SessionProvider.tsx`
  - `web/src/test/msw.ts`
- Test:
  - `web/src/api/client.test.ts`
  - `web/src/auth/session.test.ts`

**Interfaces:**
- Produces:
  - **`class ApiError extends Error`**, with fields `{status: number; code: string | null; title: string; detail: string | null; errors: {field: string; code: string; message: string}[]}`.
  - **`api<T>(method, path, options?: {body?: unknown; headers?: Record<string, string>; idempotencyKey?: string; ifMatch?: string; auth?: boolean}): Promise<{data: T; headers: Headers}>`**
    - Sends JSON with `credentials: 'include'`.
    - Adds `Authorization` when there's a token and `auth` isn't `false`.
    - On a 401, calls `session.refresh()` once and retries once.
    - Turns `application/problem+json` responses into `ApiError`.
    - Returns `data: undefined` on a 204.
  - **`session`:**
    - `getToken(): string | null`
    - `setToken(t: string | null)`
    - `refresh(): Promise<string | null>`: single-flight; `null` means signed out.
    - `subscribe(listener): () => void`
  - **`SessionProvider` / `useSession()`:** `{status: 'loading' | 'signed-in' | 'signed-out'; me: Me | null; signIn(email, password); signOut(); signOutEverywhere()}`.
    - On mount it calls `refresh()` once, then `GET /v1/me`.
    - `Me` is the generated `/v1/me` response type.

- [ ] **Step 1: Write failing tests** with MSW:
  - **Problem details:** a 422 problem body becomes an `ApiError` with `errors[0].field === 'title'`.
  - **401 retry:** a 401 followed by a successful refresh retries with the new token, and the response is returned.
  - **Concurrent 401s:** three concurrent requests that all get 401 cause exactly one `POST /v1/auth/refresh` (count the MSW handler calls), and all three resolve.
  - **Refresh failure:** if refresh returns 401, `session.getToken()` becomes `null`, the listeners hear signed-out, and the original call rejects with an `ApiError` of status 401.
  - **Headers:** `idempotencyKey` and `ifMatch` become the `Idempotency-Key` and `If-Match` headers.
- [ ] **Step 2: Run** them. Expected: FAIL.
- [ ] **Step 3: Implement.** Single-flight means keeping the in-flight refresh promise and clearing it once it settles.
- [ ] **Step 4: Run** them. Expected: PASS.
- [ ] **Step 5: Stage.**

---

### Task 8: Account pages

**Files:**
- Create: `web/src/pages/account/{SignUp,SignIn,ForgotPassword,VerifyEmail,ResetPassword,Settings}.tsx` with module CSS.
- Create:
  - `web/src/ui/Field.tsx`: label, input, hint, and an error tied to the input by `aria-describedby`.
  - `web/src/ui/ProblemMessage.tsx`: renders an `ApiError`'s title and detail.
  - `web/src/api/fieldErrors.ts`
- Test: `web/src/pages/account/account.test.tsx`

**Interfaces:**
- Consumes: `api`, `ApiError` and `useSession` (Task 7).
- Produces: `fieldErrors(e: ApiError): Record<string, string>`, mapping field to message.

**Behaviour:**
- **Sign up:** after `POST /v1/auth/register`, always show "Check your inbox to verify your email", whether or not the email was new. This matches the API's design of not revealing which emails are registered.
- **Verify email:** posts the `token` query parameter on mount.
  - Success: "Email verified" plus a sign-in link, or a studio link if already signed in.
  - `INVALID_VERIFICATION_TOKEN`: "This link has expired. Send a new one", with a resend form.
- **Reset password:** posts the token and the new password. On success, links to sign in.
- **Settings:**
  - A profile form (display name, handle, bio) sending `PATCH /v1/me`. A `UNIQUE_HANDLE` error shows on the handle field.
  - A sessions list from `GET /v1/auth/sessions`, marking the current session, with "Sign out" per session and "Sign out everywhere".

- [ ] **Step 1: Write failing tests:**
  - **Sign-up validation:** a 422 for `password` shows its message under the password field, and focus moves to the first invalid field.
  - **Sign-up success:** the sign-up page shows the inbox message after a 202.
  - **Verify email:** `/verify-email?token=abc` posts `{token: 'abc'}`. The expired-token problem shows the resend form.
  - **Sign in:** success navigates to the `?next=` path, or `/studio` by default.
  - **Settings:** a `UNIQUE_HANDLE` error appears on the handle field.
- [ ] **Step 2: Run** them. Expected: FAIL.
- [ ] **Step 3: Implement** the pages.
- [ ] **Step 4: Run** them. Expected: PASS.
- [ ] **Step 5:** Against the local stack in the browser pane: sign up, open the email in Mailpit (`http://localhost:8025`), follow the link to `localhost:3000/verify-email`, and sign in. Expected: lands on `/studio`.
- [ ] **Step 6: Stage.**

---

### Task 9: Viewer browsing — home, category, profile

**Files:**
- Create:
  - `web/src/ui/VideoTile.tsx`, `web/src/ui/VideoGrid.tsx`
  - `web/src/pages/viewer/{Home,Category,Profile}.tsx` with module CSS
  - `web/src/format.ts`
- Test: `web/src/pages/viewer/browse.test.tsx`, `web/src/format.test.ts`

**Interfaces:**
- Consumes:
  - `GET /v1/videos`
  - `GET /v1/users/{handle}/videos`
  - `GET /v1/users/{handle}`
  - `GET /v1/categories`
  - the `VideoTile` item type from the generated schema
- Produces:
  - `formatDuration(ms: number): string`: `'18:42'` and `'1:02:03'`.
  - `formatAgo(iso: string, now?: Date): string`: `'2 hours ago'`, `'yesterday'`, `'3 days ago'`, and `'12 Mar 2026'` beyond 30 days.
  - `<VideoTile video size="grid" | "feature" />`
    - The `<img>` uses a `srcset` built from `thumbnails` with matching `sizes`, `alt=""` (the title is the link text) and `loading="lazy"` (except the feature, which loads eagerly).
    - It falls back to a `--plum-700` block when there are no thumbnails.
    - Duration badge, title, and the creator name linking to `/@handle`.
  - `<VideoGrid query />`
    - Uses `useInfiniteQuery` over cursor pages.
    - Shows a "More videos" button while `has_more`.

**Behaviour:**
- **Home:** the first item is shown as the feature (16:9 image with the amber glow, title, creator, "Watch now"). The rest form the "Latest" grid.
  - Empty state: "No videos yet. Be the first to upload one", with an Upload button.
- **Category page:** titled with the category name, and filtered by it.
- **Profile page (`/@:handle`):** name, `@handle`, bio, "Joined Month YYYY", and a grid of the creator's videos.
  - A 404 shows "There's no one called @x here".
- **Categories menu:** the AppShell's Categories menu lists `GET /v1/categories` (cached for the session) and links to `/categories/:slug`.

- [ ] **Step 1: Write failing tests:**
  - **Format helpers:** the `formatDuration` and `formatAgo` cases above.
  - **Home:** with 3 items, it renders 1 feature plus 2 grid tiles. "More videos" fetches with `cursor=`.
  - **Empty home:** shows the upload prompt.
  - **Profile:** a 404 shows the message.
  - **Tile:** the creator link `href` is `/@mona.haddad`.
- [ ] **Step 2: Run** them. Expected: FAIL.
- [ ] **Step 3: Implement** the pages and components.
- [ ] **Step 4: Run** them. Expected: PASS.
- [ ] **Step 5:** In the browser pane, against local data:
  - Home, a category and a profile render.
  - Thumbnails load (network shows 200s from `localhost:4566`).
  - Layout holds at 375px and 1440px.
- [ ] **Step 6: Stage.**

---

### Task 10: Player and watch page

**Files:**
- Create:
  - `web/src/player/refresh.ts`, `web/src/player/usePlayback.ts`
  - `web/src/player/Player.tsx` and `.module.css`
  - `web/src/pages/viewer/Watch.tsx` and `.module.css`
- Test: `web/src/player/refresh.test.ts`, `web/src/pages/viewer/watch.test.tsx`

**Interfaces:**
- Consumes:
  - `GET /v1/videos/{id}` (with `creator` and `duration_ms`, from Task 4)
  - `GET /v1/videos/{id}/playback`
  - `GET /v1/users/{handle}/videos?limit=6`
- Produces:
  - `refreshDelayMs(expiresAt: string, now: number): number`: `expiresAt - now - 5 minutes`, never below 0.
  - `usePlayback(videoId)` returns `{playback, error: ApiError | null, refresh(): Promise<void>}`.
  - `<Player playback videoLanguage={string | null} />`

**Behaviour:**
- **The test player is the reference.** Copy its hls.js wiring from `api/app/Modules/Playback/resources/views/test-player.blade.php`:
  - `xhrSetup` sets `withCredentials = true`;
  - on an `ERROR` with `response.code === 403`, refresh the playback, then `hls.loadSource` and resume from the same `currentTime`;
  - schedule a refresh at `refreshDelayMs`.
- **Native HLS:** use it when `video.canPlayType('application/vnd.apple.mpegurl')` and hls.js isn't supported (Safari iOS).
- **Controls:** use native `<video controls>` plus Marquee-styled selects for captions and quality.
  - Captions come from hls `subtitleTracks`. "Off" is an option.
  - On load, choose the track whose language matches `videoLanguage` (primary subtag match).
  - Quality has "Auto" plus each level's height, using `hls.currentLevel` / `nextLevel`.
- **Watch page:**
  - The player sits on a `--plum-900` stage with the glow.
  - Below it: the title, the creator (linked), `formatAgo(published_at)`, the description (line breaks preserved), tags and the category link.
  - On the side, "More from {display_name}" (excluding the current video).
- **Playback refused:** show the API's problem title and detail in place of the player. Known codes get these messages:
  - `AGE_RESTRICTED`: "This video is age-restricted and can't be played here yet."
  - `VIDEO_NOT_PLAYABLE`: "This video is still processing. Try again in a few minutes."
- **Video not found:** "This video doesn't exist or isn't available."

- [ ] **Step 1: Write failing tests:**
  - **`refreshDelayMs`:**
    - 1 hour left gives 55 minutes.
    - 3 minutes left gives 0.
  - **Watch page with mocked APIs:**
    - Renders the title, a creator link to `/@handle`, and up to 6 "More from" tiles without the current video.
    - A playback `403 AGE_RESTRICTED` problem shows the age-restricted message.
  - **Player:** given a playback with `captions: [{language: 'en'}]` and `videoLanguage: 'en-US'`, the initially selected caption is `en`. (Mock hls.js: `vi.mock('hls.js')` with a fake that emits `MANIFEST_PARSED` and `SUBTITLE_TRACKS_UPDATED`.)
- [ ] **Step 2: Run** them. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** them. Expected: PASS.
- [ ] **Step 5:** In the browser pane, play a local published video that has captions:
  - It plays, and switching quality works.
  - The captions toggle shows and hides cues.
  - Deleting the CloudFront cookies from DevTools and seeking triggers one playback refresh, and playback continues.
  - No console errors.
- [ ] **Step 6: Stage.**

---

### Task 11: Upload engine

**Files:**
- Create: `web/src/studio/upload/uploader.ts`, `web/src/studio/upload/store.ts`
- Test: `web/src/studio/upload/uploader.test.ts`

**Interfaces:**
- Consumes: `api` (Task 7).
- Produces:
  - `type SavedUpload = {uploadId: string; videoId: string; file: {name: string; size: number; lastModified: number}; etags: Record<number, string>; completeKey: string}`
  - `store`:
    - `save(u: SavedUpload)`
    - `get(videoId): SavedUpload | null`
    - `all(): SavedUpload[]`
    - `remove(videoId)`
    - `localStorage` key: `reel.uploads.v1`. Every access is wrapped in try/catch, and the store falls back to memory.
  - `class Upload`:
    - `static start(videoId: string, file: File): Promise<Upload>`: `POST /v1/videos/{id}/uploads` with an idempotency key. On `409 UPLOAD_IN_PROGRESS` it resumes the upload named in `detail`.
    - `static resume(saved: SavedUpload, file: File): Promise<Upload>`: throws `FileMismatchError` when name, size or lastModified differ.
    - `run(): Promise<'completed'>`
    - `pause()` and `resumeRun()`
    - `abort(): Promise<void>`: `DELETE /v1/uploads/{id}`, then `store.remove`.
    - `onProgress(cb: (sentBytes: number, totalBytes: number) => void)`
    - `options: {concurrency?: number; sleep?: (ms) => Promise<void>; random?: () => number}`, so tests are deterministic.

**Behaviour:**
- Exactly as in `docs/CLIENT_UPLOAD_GUIDE.md`:
  - **Part plan:** the server's.
  - **Concurrency:** 4 on desktop, 2 when `navigator.connection?.effectiveType` is `'3g'` or worse.
  - **Part PUT:** with `XMLHttpRequest`, for upload progress events, and no extra headers.
  - **Part URLs:** refresh any URL with less than 2 minutes left, before using it.
  - **Retries:**
    - `403`: re-sign, then retry.
    - Network error, `408`, `429` and `5xx`: backoff `random() * min(30000, 1000 * 2^(attempt-1))`, up to 8 attempts.
    - `404 NoSuchUpload`: `GET /v1/uploads/{id}` and stop with that status.
    - Any other `4xx`: fail.
  - **Batches:** sign more parts when fewer than 5 signed URLs remain, up to 100 at a time.
- **Persist** each ETag as it arrives.
- **On resume:** `GET /v1/uploads/{id}` and take every ETag from `uploaded_parts`, overwriting saved ones.
- **Complete:** `POST /v1/uploads/{id}:complete` with the saved `completeKey`, listing parts 1 to `total_parts`.
  - `409 PARTS_MISSING`: upload the parts named in `detail`, then complete again.
  - `409 UPLOAD_COMPLETING`: wait 3 seconds and retry.
  - `410` / `422 UPLOAD_FAILED`: reject with an `ApiError`. The UI offers "Start again".

- [ ] **Step 1: Write failing tests** (MSW for the API; a fake XHR for S3 PUTs that returns an `ETag` per part):
  - **Full upload:** a 3-part file uploads all parts and completes with all three ETags.
  - **Concurrency:** never more than the configured number of PUTs are in flight (track the maximum).
  - **Expired URL:** a 403 on part 2 calls `parts:sign` for `[2]` and succeeds.
  - **Server errors:** a 503 twice on part 1 is retried and succeeds. `sleep` is called with jittered values within bounds.
  - **Resume:** S3 already has parts 1–2 (from `uploaded_parts`), and the saved state has only part 1. Only part 3 is PUT, and `:complete` lists parts 1–3 with the server's ETags.
  - **File mismatch:** resuming with a file of a different size throws `FileMismatchError`.
  - **Missing parts:** `409 PARTS_MISSING` naming part 2 re-uploads part 2, then completes.
  - **Upload already in progress:** `409 UPLOAD_IN_PROGRESS` on start resumes the named upload.
  - **Progress:** reaches `(size, size)`.
- [ ] **Step 2: Run** them. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** them. Expected: PASS.
- [ ] **Step 5: Stage.**

---

### Task 12: Studio list and upload page

**Files:**
- Create:
  - `web/src/studio/status.ts`
  - `web/src/studio/useUploads.ts`
  - `web/src/studio/StudioList.tsx`, `web/src/studio/UploadPage.tsx`, with module CSS
  - `web/src/ui/StatusDot.tsx`
- Test: `web/src/studio/status.test.ts`, `web/src/studio/studio.test.tsx`

**Interfaces:**
- Consumes:
  - `Upload` and `store` (Task 11)
  - `GET /v1/me/videos` (Task 4 fields)
  - `POST /v1/videos`
- Produces:
  - `statusView(status: string, processing?: {code?: string; retryable?: boolean}): {label: string; tone: 'ok' | 'warn' | 'bad' | 'muted'; actions: Array<'upload' | 'pause' | 'publish' | 'unpublish' | 'edit' | 'captions' | 'delete'>; note?: string}`
    - Implements the spec's status table exactly: its labels and actions, plus the `processing_failed` wording with and without `retryable`.
  - `useUploads()` returns `{active: Map<videoId, {upload, sent, total, state: 'running' | 'paused' | 'error'}>; start(file): Promise<videoId>; resume(videoId, file)}`
    - Lives in a provider so uploads keep running during in-app navigation.

**Behaviour:**
- **Studio list:**
  - Active uploads are pinned at the top, each with a progress bar, "about N minutes left" (from a moving average) and Pause/Resume.
  - The video rows follow the mockup: thumbnail, title, visibility and duration, a status dot with its label, caption languages, and a primary action.
  - Saved uploads in `store` whose video is still `uploading` or `upload_pending` show "Pick the same file to continue", with a file input that calls `resume`.
- **Upload page:**
  - If `me.email_verified_at` is null, show "Verify your email to upload" with a "Resend email" button instead of the picker.
  - Otherwise there's a file picker and a drop zone. Picking a file:
    - creates the video (`title` = the file name without extension, plus an idempotency key);
    - starts the upload;
    - navigates to `/studio/videos/:id`, where details can be edited while it uploads.
- **Leaving:** `beforeunload` warns while any upload is running.

- [ ] **Step 1: Write failing tests:**
  - **`statusView`:** every row of the spec's table, for example:
    - `upload_failed` gives the label "Waiting for the file" with an `upload` action.
    - `processing_failed` with `retryable: true` gives a note containing "on our side".
    - `rejected` offers only `delete`.
  - **Studio list:** shows a pinned upload with its percentage, and the resume prompt for a saved upload.
  - **Unverified creator:** sees the verify prompt, not the picker.
  - **Upload page:** picking `trip.mp4` posts `{title: 'trip'}`, then navigates to the editor.
- [ ] **Step 2: Run** them. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** them. Expected: PASS.
- [ ] **Step 5:** In the browser pane, upload a ~50 MB local file:
  - Reload mid-upload, then pick the file again.
  - Expected: it resumes, without re-sending finished parts (check the network panel), and completes.
- [ ] **Step 6: Stage.**

---

### Task 13: Video editor — details, processing, publishing

**Files:**
- Create: `web/src/studio/VideoEditor.tsx` and `.module.css`
- Test: `web/src/studio/editor.test.tsx`

**Interfaces:**
- Consumes:
  - `GET` / `PATCH` / `DELETE /v1/videos/{id}`
  - `:publish` and `:unpublish`
  - `GET /v1/videos/{id}/processing`
  - `GET /v1/categories`
  - `statusView` (Task 12)
  - `useUploads` (Task 12)

**Behaviour:**
- **Form fields:** title (max 100), description (max 5000), tags (chips, up to the API's max), category (select), language, visibility (public, unlisted or private, each with a one-line explanation), age-restricted, made for kids, comments enabled, and "Publish as soon as it's ready".
- **Save:** sends a `PATCH` with `ifMatch: video.etag`.
  - On `412`: refetch the video, show "This video changed in another tab or window. Your edits are below; review and save again", keep the form values, and switch to the new etag.
  - On `422`: show field errors.
- **Processing panel:** polls `GET …/processing` every 4 seconds while the status is one of `uploaded`, `validating`, `queued_for_processing` or `processing`. It stops otherwise, and then refetches the video.
- **Header:**
  - Shows `statusView`'s label and note. For a failed status, it maps the processing `code` through a small table to plain words; unknown codes show "Processing failed".
  - **Publish / Unpublish** follow `actions`. Publishing sends the chosen visibility. A `409 NOT_PUBLISHABLE` lists each unmet guard from `errors`.
  - **Delete** asks for confirmation ("Delete this video? This can't be undone."), then sends `DELETE` with If-Match and navigates to `/studio`.
- **While uploading:** the upload progress for this video (from `useUploads`) shows above the form.

- [ ] **Step 1: Write failing tests:**
  - **Save:** sends `If-Match: "3"`.
  - **412:** keeps the typed title in the field, shows the message, and the next save sends the new etag.
  - **Processing poll:** runs while `processing` and stops after it settles (fake timers).
  - **Publish:** `NOT_PUBLISHABLE` with two errors lists both.
  - **Delete:** confirm → `DELETE` → navigation.
- [ ] **Step 2: Run** them. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** them. Expected: PASS.
- [ ] **Step 5: Stage.**

---

### Task 14: Captions panel

**Files:**
- Create: `web/src/studio/CaptionsPanel.tsx` and `.module.css`
- Modify: `web/src/studio/VideoEditor.tsx` (mount the panel)
- Test: `web/src/studio/captions.test.tsx`

**Interfaces:**
- Consumes: the subtitles endpoints:
  - `GET` / `POST /v1/videos/{id}/subtitles`
  - `POST …/{sid}:complete`
  - `DELETE …/{sid}`
  - the form upload, per the "Subtitles" section of `docs/CLIENT_UPLOAD_GUIDE.md`

**Behaviour:**
- **List:** each track shows its label, language, kind (subtitles or captions) and status. A failed track shows its `error.detail` (for example "line 3: expected a cue timing line") with "Remove and add a fixed file".
- **Add:**
  - The creator picks a `.srt` or `.vtt` file, a language (text input with suggestions from `Intl.DisplayNames`), a kind and an optional label.
  - The panel `POST`s with `size_bytes = file.size` and `format` from the extension, plus an idempotency key.
  - It then posts the `upload.fields` followed by `file` as `multipart/form-data` to `upload.url` with `fetch`. The fields go first and the file last.
  - Then it calls `:complete`.
- **Errors:**
  - `SUBTITLE_EXISTS` or `SUBTITLE_LIMIT`: show the problem detail.
  - A file larger than 2 MiB is refused before upload: "Caption files can be up to 2 MB."
- **Polling:** while any track is `processing`, poll the list every 4 seconds.

- [ ] **Step 1: Write failing tests:**
  - **Add flow:** the order of calls is create → S3 form POST (with `file` as the last part) → complete.
  - **File too large:** a 3 MB file is refused without any request.
  - **Failed track:** shows its error detail.
  - **Delete:** sends `DELETE` and removes the row.
- [ ] **Step 2: Run** them. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** them. Expected: PASS.
- [ ] **Step 5:** In the browser pane: add `api/postman/files/captions.en.srt` to a processed video. Expected: it becomes ready, and the watch page offers English captions.
- [ ] **Step 6: Stage.**

---

### Task 15: End-to-end path and final check

**Files:**
- Create:
  - `web/playwright.config.ts`
  - `web/e2e/creator-to-viewer.spec.ts`
  - `web/e2e/fixtures/` (reuse `media-worker`'s small test clip if one exists, otherwise a 5-second clip generated with ffmpeg in a setup script, and `captions.en.srt`)
- Modify: `web/README.md`

**Behaviour:** one test against the running local stack (`make up`, the API on 8000, the worker running, and `npm run dev`):
1. Sign up with a unique email.
2. Read the verification link from the Mailpit API (`GET http://localhost:8025/api/v1/messages`), open it, then sign in.
3. Upload the fixture. Wait (up to 3 minutes) for "Ready to publish".
4. Add the captions file. Wait for it to be ready.
5. Publish as public.
6. Sign out. On home, the video is the feature. Open it.
7. Expect `video.readyState >= 3` within 30 seconds, and the captions select to offer English.
8. Open the creator link. The profile lists the video.

- [ ] **Step 1:** Write the spec. Run `npm run e2e` with the stack up. Expected: PASS.
- [ ] **Step 2: Final check:**
  - `make test-api`
  - `cd web && npm run typecheck && npm test && npm run build`
  - the Postman run from Task 5

  Expected: all green. Then use the browser pane to look over home, watch, profile, studio and the editor at 375px and 1440px against the Marquee mockups. Fix anything that reads as generic or off-token.
- [ ] **Step 3:** Update `web/README.md`: how to run it, `api:types` after API changes, and how to run the e2e test.
- [ ] **Step 4: Stage** everything. Report to the owner for review and commit.
