# Users module

Owns the `users` table: identity, public profile and account status. Other modules use `Contracts\UserDirectory` (lookups, creation, verification) and listen to `Events\*`.

## Endpoints

| Endpoint | Auth | Notes |
|---|---|---|
| `GET /v1/me` | Bearer | Own account: id, email, email_verified, handle, display_name, bio, status, created_at |
| `PATCH /v1/me` | Bearer | Change `display_name`, `handle`, `bio` (`null` clears it). Any other field, e.g. `email`, is a `422 UNKNOWN_FIELD`, not silently ignored. |
| `GET /v1/users/{handle}` | — | Public profile: handle, display_name, bio, created_at. Case-insensitive. Suspended and deleted accounts return `404`. |

## Handles

- Optional, and set by the user through `PATCH /v1/me`. Users without one have no public profile URL yet.
- 3–30 characters of letters, digits, `_` and `.`. Starts with a letter or digit, ends with a letter, digit or `_`, and has no `..`. Invalid format is error code `REGEX`.
- **Unique case-insensitively** and stored as typed: `AdaLovelace` blocks `adalovelace`. Users may change the case of their own handle.
- Reserved words (`admin`, `support`, `api`, `official` …, see `Rules\UniqueHandle::RESERVED`) are reported as taken. Taken or reserved is error code `UNIQUE_HANDLE`.
- Enforced twice: the validation rule, and a unique index on `lower(handle)` that catches two simultaneous claims and returns the same `422`.

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
