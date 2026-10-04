<?php

namespace App\Modules\Users\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An account was suspended. In-process event (other modules listen, e.g. Auth revokes every
 * session and token). Dispatched only after the status change commits.
 */
final readonly class UserSuspended implements ShouldDispatchAfterCommit
{
    public function __construct(public string $userId, public string $reason) {}
}
