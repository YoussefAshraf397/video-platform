<?php

namespace App\Modules\Users\Contracts;

/** The Users module's public API for other modules (ADR-001). */
interface UserDirectory
{
    /**
     * Creates a user with an unverified email. Emails are compared case-insensitively.
     *
     * @return UserRecord|null null when the email is already registered (race-safe)
     */
    public function create(string $email, string $displayName): ?UserRecord;

    public function find(string $id): ?UserRecord;

    public function findByEmail(string $email): ?UserRecord;

    public function markEmailVerified(string $id): void;
}
