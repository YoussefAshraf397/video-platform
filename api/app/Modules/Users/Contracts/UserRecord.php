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
        public string $role = 'user',
    ) {}

    /** Platform staff who may use the admin API (/admin/v1). */
    public function isAdmin(): bool
    {
        return $this->role === 'admin' && $this->isActive();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }
}
