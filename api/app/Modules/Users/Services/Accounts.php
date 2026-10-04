<?php

namespace App\Modules\Users\Services;

use App\Modules\Users\Events\UserSuspended;
use App\Modules\Users\Models\User;
use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Profile changes and account status, internal to the Users module. */
final class Accounts
{
    /** 3-30 of letters, digits, _ and .; starts with a letter or digit; no "..", no trailing ".". */
    public const HANDLE_PATTERN = '/^(?=.{3,30}$)[A-Za-z0-9](?:[A-Za-z0-9_]|\.(?!\.))*[A-Za-z0-9_]$/';

    /** @param array{display_name?: string, handle?: string, bio?: ?string} $changes */
    public function updateProfile(string $userId, array $changes): User
    {
        $user = User::query()->findOrFail($userId);
        $user->fill($changes);

        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            // Someone claimed the handle between validation and save.
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'The request is invalid', errors: [
                ['field' => 'handle', 'code' => 'UNIQUE_HANDLE', 'message' => 'This handle is already taken.'],
            ]);
        }

        return $user;
    }

    /** Returns false if the user doesn't exist or is already suspended. */
    public function suspend(string $userId, string $reason): bool
    {
        $changed = DB::transaction(function () use ($userId, $reason) {
            $updated = User::query()->whereKey($userId)->where('status', 'active')->update(['status' => 'suspended']) === 1;
            if ($updated) {
                event(new UserSuspended($userId, $reason));   // delivered after commit
            }

            return $updated;
        });
        if ($changed) {
            Log::info('User suspended', ['user_id' => $userId, 'reason' => $reason]);
        }

        return $changed;
    }

    public function unsuspend(string $userId): bool
    {
        $changed = User::query()->whereKey($userId)->where('status', 'suspended')->update(['status' => 'active']) === 1;
        if ($changed) {
            Log::info('User unsuspended', ['user_id' => $userId]);
        }

        return $changed;
    }
}
