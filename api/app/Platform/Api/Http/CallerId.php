<?php

namespace App\Platform\Api\Http;

use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * The authenticated caller's user id, for any module. Works with whatever user object the guard
 * returns, so modules don't depend on the Auth module's classes.
 */
final class CallerId
{
    public static function from(Request $request): string
    {
        return self::tryFrom($request) ?? throw new ApiProblem(401, 'UNAUTHENTICATED', 'Authentication required');
    }

    /** For routes that work signed in or not: null for anonymous callers (or an invalid token). */
    public static function tryFrom(Request $request): ?string
    {
        $user = ($request->getUserResolver())();

        return $user instanceof Authenticatable ? (string) $user->getAuthIdentifier() : null;
    }
}
