<?php

namespace App\Modules\Auth\Services;

final readonly class RotationResult
{
    public const ROTATED = 'rotated';

    public const INVALID = 'invalid';

    public const REUSED = 'reused';

    public const RECENTLY_ROTATED = 'recently_rotated';

    private function __construct(
        public string $outcome,
        public ?string $sessionId = null,
        public ?string $userId = null,
        public ?string $refreshToken = null,
    ) {}

    public static function rotated(string $sessionId, string $userId, string $refreshToken): self
    {
        return new self(self::ROTATED, $sessionId, $userId, $refreshToken);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID);
    }

    public static function reused(string $sessionId, string $userId): self
    {
        return new self(self::REUSED, $sessionId, $userId);
    }

    public static function recentlyRotated(): self
    {
        return new self(self::RECENTLY_ROTATED);
    }
}
