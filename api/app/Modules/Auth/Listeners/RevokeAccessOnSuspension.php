<?php

namespace App\Modules\Auth\Listeners;

use App\Modules\Auth\Services\Revocations;
use App\Modules\Auth\Services\Sessions;
use App\Modules\Users\Events\UserSuspended;

/** A suspended account is signed out everywhere at once: sessions and every issued access token. */
final class RevokeAccessOnSuspension
{
    public function __construct(
        private readonly Sessions $sessions,
        private readonly Revocations $revocations,
    ) {}

    public function handle(UserSuspended $event): void
    {
        $this->sessions->revokeAll($event->userId, 'account_suspended');
        $this->revocations->revokeTokensIssuedBefore($event->userId, now());
    }
}
