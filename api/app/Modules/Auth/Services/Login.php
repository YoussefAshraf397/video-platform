<?php

namespace App\Modules\Auth\Services;

use App\Modules\Users\Contracts\UserDirectory;
use App\Modules\Users\Contracts\UserRecord;
use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Checks email + password without revealing whether the account exists, and locks out
 * password guessing:
 *  - 5 failures per account and IP in 15 minutes: stops guessing from one machine without
 *    locking the real owner out (they sign in from their own IP);
 *  - 20 failures per account in an hour from anywhere: stops distributed guessing.
 * Unknown emails are counted the same way, so lockouts reveal nothing either.
 */
final class Login
{
    /** @var array<string, array{0: int, 1: int}> limiter scope => [max failures, window seconds] */
    private const FAILURE_LIMITS = [
        'account-ip' => [5, 15 * 60],
        'account' => [20, 60 * 60],
    ];

    private ?string $dummyHash = null;

    public function __construct(private readonly UserDirectory $users) {}

    public function attempt(string $email, string $password, string $ip): UserRecord
    {
        $account = hash('sha256', Str::lower(trim($email)));
        $keys = [
            'account-ip' => "login-failures:{$account}:{$ip}",
            'account' => "login-failures:{$account}",
        ];
        foreach ($keys as $scope => $key) {
            if (RateLimiter::tooManyAttempts($key, self::FAILURE_LIMITS[$scope][0])) {
                $retryAfter = RateLimiter::availableIn($key);
                throw new ApiProblem(429, 'TOO_MANY_REQUESTS', 'Too many sign-in attempts',
                    "Try again in {$retryAfter} seconds, or reset your password.", headers: ['Retry-After' => (string) $retryAfter]);
            }
        }

        $hasher = Hash::driver('argon2id');
        $user = $this->users->findByEmail($email);
        $hash = $user === null ? null : DB::table('credentials')->where('user_id', $user->id)->value('password_hash');

        // Always run one Argon2id verification, so unknown emails take as long as wrong passwords.
        $matches = $hasher->check($password, is_string($hash) ? $hash : $this->dummyHash($hasher));
        if ($user === null || ! is_string($hash) || ! $matches) {
            foreach ($keys as $scope => $key) {
                RateLimiter::hit($key, self::FAILURE_LIMITS[$scope][1]);
            }
            throw new ApiProblem(401, 'INVALID_CREDENTIALS', 'Invalid email or password');
        }
        // The account-wide counter is left to decay: clearing it here would let a distributed
        // attacker reset it every time the owner signs in.
        RateLimiter::clear($keys['account-ip']);

        // Only reachable with the correct password, so this reveals nothing to a guesser.
        if (! $user->isActive()) {
            throw new ApiProblem(403, 'ACCOUNT_DISABLED', 'This account is disabled');
        }

        if ($hasher->needsRehash($hash)) {   // parameters were raised since the password was set
            DB::table('credentials')->where('user_id', $user->id)
                ->update(['password_hash' => $hasher->make($password), 'updated_at' => now()]);
        }

        return $user;
    }

    private function dummyHash(Hasher $hasher): string
    {
        // Same algorithm and cost as real hashes, created once per worker.
        return $this->dummyHash ??= $hasher->make(Str::random(32));
    }
}
