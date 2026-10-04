<?php

namespace App\Modules\Users\Rules;

use App\Modules\Users\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The handle isn't used by anyone else (case-insensitively) and isn't reserved. Reserved
 * handles are reported as taken: telling "reserved" from "taken" helps nobody. Failures show up
 * as error code UNIQUE_HANDLE.
 */
final class UniqueHandle implements ValidationRule
{
    /** Words that would look official or collide with routes. */
    public const RESERVED = [
        'about', 'admin', 'administrator', 'api', 'app', 'channel', 'channels', 'help', 'login',
        'logout', 'me', 'moderator', 'null', 'official', 'privacy', 'register', 'root', 'security',
        'settings', 'signup', 'staff', 'support', 'system', 'terms', 'undefined', 'user', 'users',
        'video', 'videos', 'videoplatform',
    ];

    public function __construct(private readonly ?string $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $handle = strtolower((string) $value);
        $taken = in_array($handle, self::RESERVED, true) || User::query()
            ->whereRaw('lower(handle) = ?', [$handle])
            ->when($this->ignoreUserId, fn ($q, $id) => $q->whereKeyNot($id))
            ->exists();

        if ($taken) {
            $fail('This handle is already taken.');
        }
    }
}
