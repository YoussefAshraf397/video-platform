<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Contracts\AuthenticatedUser;
use App\Modules\Auth\Services\Sessions;
use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/** "Where you're signed in": list and revoke your own sessions. */
final class SessionsController
{
    public function __construct(private readonly Sessions $sessions) {}

    /** A user has a handful of sessions, so this list isn't paginated. */
    public function index(Request $request): JsonResponse
    {
        $caller = self::caller($request);

        return new JsonResponse(['items' => array_map(fn (object $s) => [
            'id' => $s->id,
            'user_agent' => $s->user_agent,
            'ip_address' => $s->ip_address,
            'created_at' => Carbon::parse($s->created_at)->toIso8601ZuluString(),
            'last_used_at' => Carbon::parse($s->last_used_at)->toIso8601ZuluString(),
            'current' => $s->id === $caller->sessionId,
        ], $this->sessions->active($caller->id))]);
    }

    public function destroy(Request $request, string $session): Response
    {
        $caller = self::caller($request);
        // 404 for a session that isn't the caller's: don't confirm other users' session ids.
        if (! $this->sessions->revoke($caller->id, $session, 'revoked_by_user')) {
            throw new ApiProblem(404, 'SESSION_NOT_FOUND', 'Session not found');
        }

        return new Response(status: 204);
    }

    private static function caller(Request $request): AuthenticatedUser
    {
        $user = $request->user();
        if (! $user instanceof AuthenticatedUser) {
            throw new ApiProblem(401, 'UNAUTHENTICATED', 'Authentication required');
        }

        return $user;
    }
}
