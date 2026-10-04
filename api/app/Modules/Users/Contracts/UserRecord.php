<?php

namespace App\Modules\Users\Contracts;

use DateTimeImmutable;

/** A user as other modules see it. */
final readonly class UserRecord
{
    public function __construct(
        public string $id,
        public string $email,
        public ?string $handle,
        public string $displayName,
        public string $status,
        public ?DateTimeImmutable $emailVerifiedAt,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }
}
