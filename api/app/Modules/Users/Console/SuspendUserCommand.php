<?php

namespace App\Modules\Users\Console;

use App\Modules\Users\Contracts\UserDirectory;
use App\Modules\Users\Services\Accounts;
use Illuminate\Console\Command;

/** Ops tool until the admin console (EPIC-15): suspend or reactivate an account. */
final class SuspendUserCommand extends Command
{
    protected $signature = 'users:suspend
        {email : Account email}
        {--reason= : Why (logged), e.g. a moderation case id}
        {--undo : Reactivate instead of suspending}';

    protected $description = 'Suspend an account (signs it out everywhere) or reactivate it';

    public function handle(UserDirectory $users, Accounts $accounts): int
    {
        $user = $users->findByEmail((string) $this->argument('email'));
        if ($user === null) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }

        if ($this->option('undo')) {
            $this->info($accounts->unsuspend($user->id) ? 'Reactivated.' : 'Account was not suspended.');

            return self::SUCCESS;
        }

        $reason = (string) ($this->option('reason') ?: 'manual');
        $this->info($accounts->suspend($user->id, $reason) ? 'Suspended; all sessions revoked.' : 'Account was not active.');

        return self::SUCCESS;
    }
}
