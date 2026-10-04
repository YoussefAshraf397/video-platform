<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Contracts\AuthenticatedUser;
use Illuminate\Http\Request;

/** Resolves the `api` guard's user from the `Authorization: Bearer <jwt>` header. */
final class AccessTokenGuard
{
    public function __construct(
        private readonly AccessTokens $tokens,
        private readonly Sessions $sessions,
    ) {}

    public function __invoke(Request $request): ?AuthenticatedUser
    {
        $jwt = $request->bearerToken();
        if ($jwt === null || $jwt === '') {
            return null;
        }

        $claims = $this->tokens->verify($jwt);
        if ($claims === null || $this->sessions->isRevoked($claims->sessionId)) {
            return null;
        }

        return new AuthenticatedUser($claims->userId, $claims->sessionId, $claims->emailVerified);
    }
}
