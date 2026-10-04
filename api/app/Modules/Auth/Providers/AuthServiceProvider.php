<?php

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Console\GenerateJwtKeysCommand;
use App\Modules\Auth\Services\AccessTokenGuard;
use App\Modules\Auth\Services\AccessTokens;
use App\Modules\Auth\Services\LaravelClock;
use App\Modules\Auth\Services\Login;
use App\Modules\Auth\Services\Registration;
use App\Modules\Auth\Services\Sessions;
use App\Modules\Users\Contracts\UserDirectory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AccessTokens::class, fn () => AccessTokens::fromConfig((array) config('auth.tokens'), new LaravelClock));
        $this->app->singleton(Sessions::class, fn () => new Sessions(
            (int) config('auth.tokens.refresh_ttl_seconds'),
            (int) config('auth.tokens.session_max_lifetime_seconds'),
            (int) config('auth.tokens.reuse_grace_seconds'),
            (int) config('auth.tokens.access_ttl_seconds'),
        ));
        $this->app->singleton(Registration::class, fn ($app) => new Registration(
            $app->make(UserDirectory::class),
            (int) config('auth.email_verification.ttl_seconds'),
            (string) config('auth.email_verification.url'),
        ));
        // Singleton so the dummy hash (equal timing for unknown emails) is made once per worker.
        $this->app->singleton(Login::class);
    }

    public function boot(): void
    {
        Auth::viaRequest('jwt', fn ($request) => $this->app->make(AccessTokenGuard::class)($request));

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'auth');
        $this->commands([GenerateJwtKeysCommand::class]);
    }
}
