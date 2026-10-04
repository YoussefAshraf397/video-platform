<?php

namespace App\Platform\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an ID: the caller's X-Request-Id if it is well formed, otherwise a new
 * UUIDv7. The ID is added to the request Context, so it appears in every log line and is
 * carried into queued jobs, and it is returned in the X-Request-Id response header.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $id = is_string($incoming) && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $incoming)
            ? $incoming
            : (string) Str::uuid7();

        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
