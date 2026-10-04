# Auth module

Sign-up, email verification, login, tokens and sessions (design doc §23). People exist in the **Users** module; Auth owns only their credentials and sessions and reaches Users through `UserDirectory`.

## Endpoints (`/v1/auth`)

| Endpoint | Auth | Notes |
|---|---|---|
| `POST register` | — | Always `202`, whether or not the email exists (no account enumeration). New emails get a verification link; registered ones get "you already have an account". |
| `POST email/verify` | — | `{token}` from the emailed link. Single use, valid 24 h. |
| `POST email/resend` | — | Always `202`. |
| `POST login` | — | `{access_token, token_type, expires_in, user}` + refresh cookie. Wrong password and unknown email give the same `401 INVALID_CREDENTIALS`. |
| `POST refresh` | refresh cookie | New access token + rotated cookie. Origin must be in `FRONTEND_ORIGINS`. |
| `POST logout` | Bearer | Revokes this session. Its access tokens stop working immediately. |
| `POST logout-all` | Bearer | Revokes every session of the user. |
| `GET sessions` | Bearer | Active sessions, with `current` marking this one. |
| `DELETE sessions/{id}` | Bearer | Revoke one of your own sessions. Another user's session returns `404`. |

## Tokens

- **Access token:** a JWT signed with Ed25519 (`EdDSA`), lasting 15 minutes. Claims: `iss`, `aud`, `sub` (user id), `sid` (session id), `jti`, `iat`, `nbf`, `exp`, `email_verified`. Clients send it as `Authorization: Bearer …`. `$request->user()` returns an `AuthenticatedUser` built from the token alone, with no database query.
- **Refresh token:** an opaque 256-bit random value, stored only as a SHA-256 hash.
  - It is delivered **only** as an `HttpOnly; Secure; SameSite=Strict` cookie scoped to `/v1/auth`, so scripts can't read it.
  - Every refresh rotates it, sliding 30 days, with an absolute 90-day session limit.
  - **Presenting an already-rotated token revokes the whole session**, because it means the token was copied.
  - A 20-second grace window answers `409` instead of revoking, so two tabs refreshing at once don't sign the user out.
- **Revocation:** revoked session ids sit in the cache for one access-token lifetime, so logout takes effect at once. If the cache is down, the check fails open: a revoked token stays usable for at most its remaining minutes.

## Security notes

- Passwords: Argon2id (64 MiB, 4 passes, about 150 ms), 10–128 characters. Hashes are upgraded automatically on login when parameters are raised.
- Timing: registration always hashes, and login of an unknown email verifies against a dummy hash, so response time doesn't reveal which emails exist.
- CSRF: the refresh endpoint is protected by SameSite=Strict plus an Origin allowlist. Bearer-token endpoints aren't CSRF-able.
- CORS: only `FRONTEND_ORIGINS`, with credentials.

## Keys

`php artisan auth:jwt-keys` prints a new key pair as `JWT_KEY_ID`, `JWT_PRIVATE_KEY`, `JWT_PUBLIC_KEY`. To rotate without signing anyone out:
1. Move the current id and public key to `JWT_PREVIOUS_KEY_ID` / `JWT_PREVIOUS_PUBLIC_KEY`.
2. Deploy the new pair.
3. Remove the previous key after 15 minutes.

## Coming in S2-04

Password reset, a per-user `min_iat` (password change or suspension invalidates all access tokens), rate limits and lockout on login/register/resend, and MFA for staff.
