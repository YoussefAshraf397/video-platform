<?php

namespace App\Modules\Users\Providers;

use App\Modules\Users\Console\SuspendUserCommand;
use App\Modules\Users\Contracts\UserDirectory;
use App\Modules\Users\Services\EloquentUserDirectory;
use Illuminate\Support\ServiceProvider;

final class UsersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UserDirectory::class, EloquentUserDirectory::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
        $this->commands([SuspendUserCommand::class]);
    }
}
