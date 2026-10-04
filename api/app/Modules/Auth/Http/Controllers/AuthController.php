<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Contracts\AuthenticatedUser;
use App\Modules\Auth\Services\AccessTokens;
use App\Modules\Auth\Services\Login;
use App\Modules\Auth\Services\Registration;
use App\Modules\Auth\Services\RotationResult;
use App\Modules\Auth\Services\Sessions;
use App\Modules\Users\Contracts\UserDirectory;
use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;

final class AuthController
{
    public function __construct(
        private readonly AccessTokens $tokens,
        private readonly Sessions $sessions,
        private readonly UserDirectory $users,
    ) {}

    /** Always 202, whether or not the email is already registered (no account enumeration). */
    public function register(Request $request, Registration $registration): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'min:10', 'max:128'],
            'display_name' => ['required', 'string', 'max:100'],
        ]);
        $registration->register($data['email'], $data['password'], $data['display_name']);

        return new JsonResponse(['message' => 'Check your email to continue.'], 202);
    }

    public function verifyEmail(Request $request, Registration $registration): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128']]);
        $registration->verifyEmail($data['token']);

        return new JsonResponse(['email_verified' => true]);
    }

    /** Always 202, whatever the email's state. */
    public function resendVerification(Request $request, Registration $registration): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254']]);
        $registration->resendVerification($data['email']);

        return new JsonResponse(['message' => 'If this email needs verifying, we sent a new link.'], 202);
    }

    public function login(Request $request, Login $login): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
        ]);
        $user = $login->attempt($data['email'], $data['password']);
        $session = $this->sessions->start($user->id, $request->userAgent(), $request->ip());

        return $this->tokenResponse($user->id, $session['session_id'], $user->isEmailVerified(), $session['refresh_token'], [
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'display_name' => $user->displayName,
                'email_verified' => $user->isEmailVerified(),
            ],
        ]);
    }

    /** Exchanges the refresh-token cookie for a new access token and a new refresh token. */
    public function refresh(Request $request): JsonResponse
    {
        $token = $request->cookie((string) config('auth.tokens.refresh_cookie'));
        $result = is_string($token) && $token !== ''
            ? $this->sessions->rotate($token, $request->userAgent(), $request->ip())
            : RotationResult::invalid();

        if ($result->outcome === RotationResult::RECENTLY_ROTATED) {
            // Another tab refreshed a moment ago; it holds the new cookie. Not an attack.
            throw new ApiProblem(409, 'REFRESH_TOKEN_ALREADY_ROTATED', 'This session was just refreshed', 'Retry the request.');
        }
        if ($result->outcome !== RotationResult::ROTATED) {
            // Invalid, expired, revoked, or reused (which also revoked the whole session).
            throw new ApiProblem(401, 'INVALID_REFRESH_TOKEN', 'Sign in again', headers: [
                'Set-Cookie' => (string) $this->forgetRefreshCookie(),
            ]);
        }

        $user = $this->users->find((string) $result->userId);
        if ($user === null || ! $user->isActive()) {
            $this->sessions->revoke((string) $result->userId, (string) $result->sessionId, 'account_disabled');
            throw new ApiProblem(401, 'INVALID_REFRESH_TOKEN', 'Sign in again');
        }

        return $this->tokenResponse($user->id, (string) $result->sessionId, $user->isEmailVerified(), (string) $result->refreshToken);
    }

    public function logout(Request $request): Response
    {
        $caller = self::caller($request);
        $this->sessions->revoke($caller->id, $caller->sessionId, 'logout');

        return (new Response(status: 204))->withCookie($this->forgetRefreshCookie());
    }

    public function logoutAll(Request $request): Response
    {
        $this->sessions->revokeAll(self::caller($request)->id, 'logout_all');

        return (new Response(status: 204))->withCookie($this->forgetRefreshCookie());
    }

    /** @param array<string, mixed> $extra */
    private function tokenResponse(string $userId, string $sessionId, bool $emailVerified, string $refreshToken, array $extra = []): JsonResponse
    {
        return (new JsonResponse([
            'access_token' => $this->tokens->issue($userId, $sessionId, $emailVerified),
            'token_type' => 'Bearer',
            'expires_in' => $this->tokens->ttlSeconds(),
            ...$extra,
        ]))->withCookie($this->refreshCookie($refreshToken));
    }

    /**
     * The refresh token lives only in this cookie: HttpOnly (scripts can't read it), Secure,
     * SameSite=Strict, and sent only to /v1/auth.
     */
    private function refreshCookie(string $token): Cookie
    {
        return Cookie::create((string) config('auth.tokens.refresh_cookie'), $token)
            ->withExpires(now()->addSeconds((int) config('auth.tokens.refresh_ttl_seconds')))
            ->withPath('/v1/auth')
            ->withSecure()
            ->withHttpOnly()
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    private function forgetRefreshCookie(): Cookie
    {
        return $this->refreshCookie('')->withExpires(1);
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
