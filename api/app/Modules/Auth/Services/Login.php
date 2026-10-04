<?php

namespace App\Modules\Auth\Services;

use App\Modules\Users\Contracts\UserDirectory;
use App\Modules\Users\Contracts\UserRecord;
use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Checks email + password without revealing whether the account exists. */
final class Login
{
    private ?string $dummyHash = null;

    public function __construct(private readonly UserDirectory $users) {}

    public function attempt(string $email, string $password): UserRecord
    {
        $hasher = Hash::driver('argon2id');
        $user = $this->users->findByEmail($email);
        $hash = $user === null ? null : DB::table('credentials')->where('user_id', $user->id)->value('password_hash');

        // Always run one Argon2id verification, so unknown emails take as long as wrong passwords.
        $matches = $hasher->check($password, is_string($hash) ? $hash : $this->dummyHash($hasher));
        if ($user === null || ! is_string($hash) || ! $matches) {
            throw new ApiProblem(401, 'INVALID_CREDENTIALS', 'Invalid email or password');
        }

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
