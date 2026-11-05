<?php

namespace App\Modules\Users\Services;

use App\Modules\Users\Contracts\UserDirectory;
use App\Modules\Users\Contracts\UserRecord;
use App\Modules\Users\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

final class EloquentUserDirectory implements UserDirectory
{
    private const MAX_HANDLE_ATTEMPTS = 10;

    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    public function create(string $email, string $displayName): ?UserRecord
    {
        $email = self::normalizeEmail($email);
        $id = (string) Str::uuid7();
        $attempts = 0;

        foreach (Handles::candidates($displayName) as $handle) {
            if (User::query()->whereRaw('lower(handle) = ?', [Str::lower($handle)])->exists()) {
                continue;
            }

            // ON CONFLICT DO NOTHING: no exception, so it is safe inside a caller's transaction
            // and two concurrent registrations of one email (or one handle) can't both succeed.
            $inserted = User::query()->insertOrIgnore([
                'id' => $id,
                'email' => $email,
                'handle' => $handle,
                'display_name' => trim($displayName),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 1) {
                return $this->find($id);
            }

            if (User::query()->where('email', $email)->exists()) {
                return null;
            }

            // The handle was taken between our check and the insert: try the next candidate.
            if (++$attempts >= self::MAX_HANDLE_ATTEMPTS) {
                break;
            }
        }

        throw new RuntimeException('Could not allocate a unique handle.');
    }

    public function find(string $id): ?UserRecord
    {
        return self::toRecord(User::query()->find($id));
    }

    public function findByEmail(string $email): ?UserRecord
    {
        return self::toRecord(User::query()->where('email', self::normalizeEmail($email))->first());
    }

    public function findByHandle(string $handle): ?UserRecord
    {
        return self::toRecord(User::query()
            ->whereRaw('lower(handle) = ?', [Str::lower($handle)])
            ->where('status', 'active')
            ->first());
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $records = [];
        foreach (User::query()->whereIn('id', $ids)->get() as $user) {
            $records[$user->id] = self::record($user);
        }

        return $records;
    }

    public function markEmailVerified(string $id): void
    {
        User::query()->whereKey($id)->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    private static function toRecord(?User $user): ?UserRecord
    {
        return $user === null ? null : self::record($user);
    }

    private static function record(User $user): UserRecord
    {
        return new UserRecord(
            $user->id,
            $user->email,
            $user->handle,
            $user->display_name,
            $user->status,
            $user->email_verified_at,
            $user->role,
        );
    }
}
