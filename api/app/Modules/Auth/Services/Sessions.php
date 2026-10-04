<?php

namespace App\Modules\Auth\Services;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Signed-in sessions and their rotating refresh tokens.
 *
 * Each refresh exchanges the presented token for a new one. Presenting an already-exchanged
 * token means it was copied, so the whole session (every token in the family) is revoked.
 */
final class Sessions
{
    public function __construct(
        private readonly int $refreshTtlSeconds,
        private readonly int $maxLifetimeSeconds,
        private readonly int $reuseGraceSeconds,
        private readonly Revocations $revocations,
    ) {}

    /** @return array{session_id: string, refresh_token: string} */
    public function start(string $userId, ?string $userAgent, ?string $ip): array
    {
        $sessionId = (string) Str::uuid7();
        $now = now();
        $expiresAt = $now->copy()->addSeconds($this->maxLifetimeSeconds);

        return DB::transaction(function () use ($sessionId, $userId, $userAgent, $ip, $now, $expiresAt) {
            DB::table('auth_sessions')->insert([
                'id' => $sessionId,
                'user_id' => $userId,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
                'ip_address' => $ip,
                'created_at' => $now,
                'last_used_at' => $now,
                'expires_at' => $expiresAt,
            ]);

            return ['session_id' => $sessionId, 'refresh_token' => $this->newRefreshToken($sessionId, $expiresAt)];
        });
    }

    public function rotate(string $refreshToken, ?string $userAgent, ?string $ip): RotationResult
    {
        $hash = self::hash($refreshToken);

        $result = DB::transaction(function () use ($hash, $userAgent, $ip) {
            $token = DB::table('refresh_tokens')->where('token_hash', $hash)->lockForUpdate()->first();
            if ($token === null) {
                return RotationResult::invalid();
            }
            $session = DB::table('auth_sessions')->where('id', $token->session_id)->lockForUpdate()->first();
            if ($session === null || $session->revoked_at !== null) {
                return RotationResult::invalid();
            }

            if ($token->rotated_at !== null) {
                if (now()->diffInSeconds($token->rotated_at, absolute: true) <= $this->reuseGraceSeconds) {
                    return RotationResult::recentlyRotated();
                }
                $this->markRevoked($session->id, 'refresh_token_reuse');

                return RotationResult::reused($session->id, $session->user_id);
            }

            if (now()->gte($token->expires_at) || now()->gte($session->expires_at)) {
                return RotationResult::invalid();
            }

            DB::table('refresh_tokens')->where('token_hash', $hash)->update(['rotated_at' => now()]);
            DB::table('auth_sessions')->where('id', $session->id)->update([
                'last_used_at' => now(),
                'user_agent' => $userAgent === null ? $session->user_agent : mb_substr($userAgent, 0, 512),
                'ip_address' => $ip ?? $session->ip_address,
            ]);

            return RotationResult::rotated(
                $session->id,
                $session->user_id,
                $this->newRefreshToken($session->id, Carbon::parse($session->expires_at)),
            );
        });

        if ($result->outcome === RotationResult::REUSED && $result->sessionId !== null) {
            // After commit, so the revocation is never rolled back with a failed response.
            $this->revocations->revokeSession($result->sessionId);
            Log::warning('Refresh token reuse detected; session revoked', [
                'session_id' => $result->sessionId,
                'user_id' => $result->userId,
                'ip' => $ip,
            ]);
        }

        return $result;
    }

    /** Revokes one session of a user. Returns false if it doesn't exist or isn't theirs. */
    public function revoke(string $userId, string $sessionId, string $reason): bool
    {
        $revoked = DB::table('auth_sessions')
            ->where('id', $sessionId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoke_reason' => $reason]) === 1;
        if ($revoked) {
            $this->revocations->revokeSession($sessionId);
        }

        return $revoked || DB::table('auth_sessions')->where('id', $sessionId)->where('user_id', $userId)->exists();
    }

    public function revokeAll(string $userId, string $reason): void
    {
        $ids = DB::table('auth_sessions')->where('user_id', $userId)->whereNull('revoked_at')->pluck('id');
        DB::table('auth_sessions')->whereIn('id', $ids)->update(['revoked_at' => now(), 'revoke_reason' => $reason]);
        foreach ($ids as $id) {
            $this->revocations->revokeSession($id);
        }
    }

    /** @return list<\stdClass> rows with id, user_agent, ip_address, created_at, last_used_at */
    public function active(string $userId): array
    {
        return array_values(DB::table('auth_sessions')
            ->select(['id', 'user_agent', 'ip_address', 'created_at', 'last_used_at'])
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('last_used_at')
            ->get()
            ->all());
    }

    private function newRefreshToken(string $sessionId, DateTimeInterface $sessionExpiresAt): string
    {
        $token = 'rt_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = now()->addSeconds($this->refreshTtlSeconds);
        DB::table('refresh_tokens')->insert([
            'token_hash' => self::hash($token),
            'session_id' => $sessionId,
            'created_at' => now(),
            'expires_at' => $expiresAt->lt($sessionExpiresAt) ? $expiresAt : $sessionExpiresAt,
        ]);

        return $token;
    }

    private function markRevoked(string $sessionId, string $reason): void
    {
        DB::table('auth_sessions')->where('id', $sessionId)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoke_reason' => $reason]);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
