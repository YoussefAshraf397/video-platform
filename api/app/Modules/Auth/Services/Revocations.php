<?php

namespace App\Modules\Auth\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Makes still-unexpired access tokens unusable. Access tokens are stateless JWTs, so revocations
 * are kept in the cache for one access-token lifetime: after that the tokens expire anyway.
 *
 *  - a revoked session: every token carrying that `sid`;
 *  - a user's `min_iat`: every token of that user issued before a moment (password reset,
 *    and later suspension).
 *
 * If the cache is unreachable the check fails open: refusing every request would take the API
 * down, while a revoked token stays usable for at most its remaining minutes.
 */
final class Revocations
{
    public function __construct(private readonly int $accessTtlSeconds) {}

    public function revokeSession(string $sessionId): void
    {
        Cache::put("auth:revoked-session:{$sessionId}", true, $this->ttl());
    }

    public function revokeTokensIssuedBefore(string $userId, DateTimeInterface $moment): void
    {
        Cache::put("auth:min-iat:{$userId}", $moment->getTimestamp(), $this->ttl());
    }

    public function isRevoked(AccessTokenClaims $claims): bool
    {
        try {
            $values = Cache::many(["auth:revoked-session:{$claims->sessionId}", "auth:min-iat:{$claims->userId}"]);
        } catch (Throwable $e) {
            Log::warning('Token revocation check unavailable; allowing token', ['error' => $e->getMessage()]);

            return false;
        }
        $minIat = $values["auth:min-iat:{$claims->userId}"];

        return $values["auth:revoked-session:{$claims->sessionId}"] !== null
            || (is_int($minIat) && $claims->issuedAt->getTimestamp() < $minIat);
    }

    private function ttl(): int
    {
        return $this->accessTtlSeconds + 60;   // + clock leeway
    }
}
