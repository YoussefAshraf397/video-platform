<?php

namespace App\Modules\Auth\Http\Middleware;

use App\Platform\Api\Errors\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF protection for endpoints authenticated only by a cookie (refresh). Browsers always send
 * Origin on cross-origin POSTs, so a request from another site is refused. Requests without
 * Origin come from non-browser clients, which can't be tricked into sending the cookie.
 * SameSite=Strict on the cookie is the first line of defence; this is the second.
 */
final class RequireAllowedOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        if ($origin !== null && ! in_array($origin, (array) config('auth.tokens.allowed_origins'), true)) {
            throw new ApiProblem(403, 'ORIGIN_NOT_ALLOWED', 'Request origin is not allowed');
        }

        return $next($request);
    }
}
