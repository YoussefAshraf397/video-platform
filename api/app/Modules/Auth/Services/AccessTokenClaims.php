<?php

namespace App\Modules\Auth\Services;

use DateTimeImmutable;

final readonly class AccessTokenClaims
{
    public function __construct(
        public string $userId,
        public string $sessionId,
        public bool $emailVerified,
        public DateTimeImmutable $issuedAt,
    ) {}
}
