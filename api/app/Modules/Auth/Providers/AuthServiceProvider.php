<?php

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Console\GenerateJwtKeysCommand;
use App\Modules\Auth\Listeners\RevokeAccessOnSuspension;
use App\Modules\Auth\Services\AccessTokenGuard;
use App\Modules\Auth\Services\AccessTokens;
use App\Modules\Auth\Services\LaravelClock;
use App\Modules\Auth\Services\Login;
use App\Modules\Auth\Services\PasswordReset;
use App\Modules\Auth\Services\Registration;
use App\Modules\Auth\Services\Revocations;
use App\Modules\Auth\Services\Sessions;
use App\Modules\Users\Contracts\UserDirectory;
use App\Modules\Users\Events\UserSuspended;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AccessTokens::class, fn () => AccessTokens::fromConfig((array) config('auth.tokens'), new LaravelClock));
        $this->app->singleton(Revocations::class, fn () => new Revocations((int) config('auth.tokens.access_ttl_seconds')));
        $this->app->singleton(Sessions::class, fn ($app) => new Sessions(
            (int) config('auth.tokens.refresh_ttl_seconds'),
            (int) config('auth.tokens.session_max_lifetime_seconds'),
            (int) config('auth.tokens.reuse_grace_seconds'),
            $app->make(Revocations::class),
        ));
        $this->app->singleton(Registration::class, fn ($app) => new Registration(
            $app->make(UserDirectory::class),
            (int) config('auth.email_verification.ttl_seconds'),
            (string) config('auth.email_verification.url'),
        ));
        $this->app->singleton(PasswordReset::class, fn ($app) => new PasswordReset(
            $app->make(UserDirectory::class),
            $app->make(Sessions::class),
            $app->make(Revocations::class),
            (int) config('auth.password_reset.ttl_seconds'),
            (string) config('auth.password_reset.url'),
        ));
        // Singleton so the dummy hash (equal timing for unknown emails) is made once per worker.
        $this->app->singleton(Login::class);
    }

    public function boot(): void
    {
        $this->defineRateLimits();
        Auth::viaRequest('jwt', fn ($request) => $this->app->make(AccessTokenGuard::class)($request));
        Event::listen(UserSuspended::class, RevokeAccessOnSuspension::class);

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'auth');
        $this->commands([GenerateJwtKeysCommand::class]);
    }

    /**
     * Per-IP and per-email limits for unauthenticated endpoints (design doc §24.3). Exceeding
     * one answers 429 with Retry-After. Login failures per account are limited in Login.
     * Every limit has its own key: limits sharing a key would share one counter.
     */
    private function defineRateLimits(): void
    {
        $email = fn (Request $request) => hash('sha256', Str::lower(trim((string) $request->input('email'))));

        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(10)->by('ip-minute:'.$request->ip()),
            Limit::perHour(100)->by('ip-hour:'.$request->ip()),
        ]);
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perHour(5)->by('ip:'.$request->ip()));
        // Password reset and verification resend: both send email to an address the caller names.
        RateLimiter::for('auth-email', fn (Request $request) => [
            Limit::perHour(5)->by('ip:'.$request->ip()),
            Limit::perHour(3)->by('email:'.$email($request)),
        ]);
    }
}
