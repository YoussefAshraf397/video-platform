<?php

namespace App\Modules\Users\Http\Middleware;

use App\Modules\Users\Contracts\UserDirectory;
use App\Platform\Api\Errors\ApiProblem;
use App\Platform\Api\Http\CallerId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `admin`: only active admins reach the admin API. The role is read from the
 * database on every request (not from the token), so taking it away works immediately.
 */
final class RequireAdmin
{
    public function __construct(private readonly UserDirectory $users) {}

    public function handle(Request $request, Closure $next): Response
    {
        $callerId = CallerId::from($request);
        if (! $this->users->find($callerId)?->isAdmin()) {
            throw new ApiProblem(403, 'FORBIDDEN', 'Admins only');
        }
        Context::add('admin_id', $callerId);   // every log line of the request says which admin acted

        return $next($request);
    }
}
