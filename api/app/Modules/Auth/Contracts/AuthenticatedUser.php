<?php

namespace App\Modules\Auth\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use LogicException;

/**
 * The caller of an authenticated request, as returned by $request->user(). Built from the
 * verified access token alone (no database lookup). Other modules use getAuthIdentifier()
 * and, when they need profile data, ask the Users module.
 */
final readonly class AuthenticatedUser implements Authenticatable
{
    public function __construct(
        public string $id,
        public string $sessionId,
        public bool $emailVerified,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }

    // There are no passwords or "remember me" cookies on this principal.

    public function getAuthPasswordName(): string
    {
        throw new LogicException('Token-authenticated users have no password field.');
    }

    public function getAuthPassword(): string
    {
        throw new LogicException('Token-authenticated users have no password field.');
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
