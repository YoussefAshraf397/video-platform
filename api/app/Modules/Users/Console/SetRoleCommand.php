<?php

namespace App\Modules\Users\Console;

use App\Modules\Users\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Grants or removes a staff role. Until the admin console has user management, ops use this. */
final class SetRoleCommand extends Command
{
    protected $signature = 'users:role {email} {role : user, moderator or admin}';

    protected $description = 'Set a user\'s staff role';

    public function handle(): int
    {
        $role = (string) $this->argument('role');
        if (! in_array($role, ['user', 'moderator', 'admin'], true)) {
            $this->error('The role must be user, moderator or admin.');

            return self::FAILURE;
        }
        $updated = User::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->update(['role' => $role, 'updated_at' => now()]);
        if ($updated === 0) {
            $this->error('No such user.');

            return self::FAILURE;
        }
        Log::notice('user.role_changed', ['email' => $this->argument('email'), 'role' => $role]);
        $this->info("{$this->argument('email')} is now {$role}.");

        return self::SUCCESS;
    }
}
