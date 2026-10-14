<?php

namespace App\Modules\Uploads\Providers;

use App\Modules\Uploads\Console\SweepUploadsCommand;
use App\Modules\Uploads\Contracts\UploadedSources;
use App\Modules\Uploads\Services\EloquentUploadedSources;
use App\Platform\Api\Http\CallerId;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class UploadsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UploadedSources::class, EloquentUploadedSources::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
        $this->commands([SweepUploadsCommand::class]);
        $this->defineRateLimits();

        // Abandoned and stuck uploads (design doc §10.5). Each session is handled idempotently, so
        // an overlapping or repeated run is harmless; withoutOverlapping just avoids wasted work.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(SweepUploadsCommand::class)->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
        });
    }

    /**
     * Design doc §24.3. These sit on top of the daily session quota (UploadSessions). Part signing
     * is generous on purpose: a large upload legitimately asks for thousands of URLs.
     * Every limit in a limiter has its own key, or they would share one counter.
     */
    private function defineRateLimits(): void
    {
        RateLimiter::for('uploads-create', fn (Request $request) => [
            Limit::perHour(30)->by('ip:'.$request->ip()),
            Limit::perHour(20)->by('user:'.CallerId::from($request)),
        ]);
        RateLimiter::for('uploads-sign', fn (Request $request) => [
            Limit::perMinute(600)->by('ip:'.$request->ip()),
            Limit::perMinute(600)->by('user:'.CallerId::from($request)),
        ]);
    }
}
