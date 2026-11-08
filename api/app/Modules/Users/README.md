# Users module

Owns the `users` table: identity, public profile and account status. Other modules use `Contracts\UserDirectory` (lookups by id or handle, `findMany` for a batch, creation, verification) and listen to `Events\*`.

## Endpoints

| Endpoint | Auth | Notes |
|---|---|---|
| `GET /v1/me` | Bearer | Own account: id, email, email_verified, handle, display_name, bio, status, created_at |
| `PATCH /v1/me` | Bearer | Change `display_name`, `handle`, `bio` (`null` clears it). Any other field, e.g. `email`, is a `422 UNKNOWN_FIELD`, not silently ignored. |
| `GET /v1/users/{handle}` | — | Public profile: handle, display_name, bio, created_at. Case-insensitive. Suspended and deleted accounts return `404`. |
| `GET /v1/users/{handle}/videos` | — | That creator's public videos. Served by the Videos module, see its [README](../Videos/README.md#public-listings). |

## Handles

- **Every account has one.** It is generated at registration from the display name and can be changed through `PATCH /v1/me`. The column is `NOT NULL`; the migration `2026_11_06_000001_backfill_user_handles` gave one to every account that lacked it, oldest first.
- **Generation** (`Services\Handles`, used by `UserDirectory::create`): the display name is reduced to Latin-script letters and digits, transliterated to lowercase ASCII (`José Núñez` → `jose.nunez`), and each run of other characters becomes one `.`. Names with no Latin letters (Arabic, Han, emoji) fall back to `user.NNNNNN`; a result shorter than 3 characters gets `.user` appended. The base is cut to 30 characters.
- **Collisions:** the base is tried first, then `base.2` … `base.9`, then `base.NNNN` with a random 4-digit suffix until one is free. Suffixes make room by trimming the base, so the result never exceeds 30 characters. Candidates are checked against `lower(handle)` and the unique index settles races.
- Every generated handle matches `Accounts::HANDLE_PATTERN`, the same pattern `PATCH /v1/me` enforces.
- 3–30 characters of letters, digits, `_` and `.`. Starts with a letter or digit, ends with a letter, digit or `_`, and has no `..`. Invalid format is error code `REGEX`.
- **Unique case-insensitively** and stored as typed: `AdaLovelace` blocks `adalovelace`. Users may change the case of their own handle.
- Reserved words (`admin`, `support`, `api`, `official` …, see `Rules\UniqueHandle::RESERVED`) are reported as taken. Taken or reserved is error code `UNIQUE_HANDLE`.
- Enforced twice: the validation rule, and a unique index on `lower(handle)` that catches two simultaneous claims and returns the same `422`.

## Staff roles

`users.role`: `user` (default), `moderator`, `admin` (design doc §23). Creator and viewer rights come from ownership, not roles.

- Route middleware `admin` (`Http\Middleware\RequireAdmin`) guards the admin API (`/admin/v1`). It reads the role from the database on every request, not from the token, so removing a role takes effect immediately. It also puts `admin_id` on every log line of the request.
- `UserRecord::isAdmin()` is true only for an active admin.
- Until the admin console manages staff: `php artisan users:role someone@example.com admin` (or `user`).

## Account status

`active` | `suspended` | `deleted` (deleted arrives with the account-deletion saga).

**Suspending** (`Services\Accounts::suspend`) dispatches `Events\UserSuspended` after commit. The Auth module then revokes every session and every access token already issued, so a suspended user is cut off immediately on all devices, not when tokens expire. A suspended user also can't sign in or refresh (`403 ACCOUNT_DISABLED` / `401`), and their profile is hidden.

Until the admin console (EPIC-15), ops use:

```bash
php artisan users:suspend someone@example.com --reason=case-123
php artisan users:suspend someone@example.com --undo     # reactivate
```

## Not yet built

- Avatars and banners (needs the image upload pipeline).
- Changing the email (needs re-verification of the new address).
- Handle change cooldown and a history of old handles (to stop squatting on a handle someone just gave up).
