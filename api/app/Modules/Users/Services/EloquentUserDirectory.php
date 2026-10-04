<?php

namespace App\Modules\Users\Services;

use App\Modules\Users\Contracts\UserDirectory;
use App\Modules\Users\Contracts\UserRecord;
use App\Modules\Users\Models\User;
use Illuminate\Support\Str;

final class EloquentUserDirectory implements UserDirectory
{
    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    public function create(string $email, string $displayName): ?UserRecord
    {
        $id = (string) Str::uuid7();
        // ON CONFLICT DO NOTHING: no exception, so it is safe inside a caller's transaction
        // and two concurrent registrations of one email can't both succeed.
        $inserted = User::query()->insertOrIgnore([
            'id' => $id,
            'email' => self::normalizeEmail($email),
            'display_name' => trim($displayName),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $inserted === 1 ? $this->find($id) : null;
    }

    public function find(string $id): ?UserRecord
    {
        return self::toRecord(User::query()->find($id));
    }

    public function findByEmail(string $email): ?UserRecord
    {
        return self::toRecord(User::query()->where('email', self::normalizeEmail($email))->first());
    }

    public function markEmailVerified(string $id): void
    {
        User::query()->whereKey($id)->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    private static function toRecord(?User $user): ?UserRecord
    {
        return $user === null ? null : new UserRecord(
            $user->id,
            $user->email,
            $user->handle,
            $user->display_name,
            $user->status,
            $user->email_verified_at,
        );
    }
}
