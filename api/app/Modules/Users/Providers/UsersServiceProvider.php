<?php

namespace App\Modules\Users\Providers;

use App\Modules\Users\Console\SetRoleCommand;
use App\Modules\Users\Console\SuspendUserCommand;
use App\Modules\Users\Contracts\UserDirectory;
use App\Modules\Users\Http\Middleware\RequireAdmin;
use App\Modules\Users\Services\EloquentUserDirectory;
use App\Platform\Api\Http\CallerId;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->commands([SuspendUserCommand::class, SetRoleCommand::class]);
        $this->app->make(Router::class)->aliasMiddleware('admin', RequireAdmin::class);
        // Admin API budget per staff member (design doc §24.3); routes add `throttle:admin` after `admin`.
        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute(600)->by('staff:'.CallerId::from($request)));
    }
}
