<?php

namespace App\Modules\Users\Services;

use Generator;
use Illuminate\Support\Str;

/** Generates handle candidates from a display name. Every candidate matches Accounts::HANDLE_PATTERN. */
final class Handles
{
    private const MAX_LENGTH = 30;

    private const MIN_LENGTH = 3;

    /** @return Generator<int, string> base, base.2 ... base.9, then base.NNNN forever */
    public static function candidates(string $displayName): Generator
    {
        $base = self::base($displayName);

        yield $base;

        for ($n = 2; $n <= 9; $n++) {
            yield self::withSuffix($base, (string) $n);
        }

        while (true) {
            yield self::withSuffix($base, str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT));
        }
    }

    private static function base(string $displayName): string
    {
        // Only Latin-script letters survive: transliterating Arabic or Han would give unreadable handles.
        $latin = (string) preg_replace('/[^\p{Latin}\p{M}\p{Nd}]+/u', ' ', $displayName);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '.', Str::lower(Str::ascii($latin))), '.');

        if ($slug === '') {
            return 'user.'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        }

        if (strlen($slug) < self::MIN_LENGTH) {
            $slug .= '.user';
        }

        return rtrim(substr($slug, 0, self::MAX_LENGTH), '.');
    }

    private static function withSuffix(string $base, string $suffix): string
    {
        $room = self::MAX_LENGTH - strlen($suffix) - 1;

        return rtrim(substr($base, 0, $room), '.').'.'.$suffix;
    }
}
