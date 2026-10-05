<?php

namespace App\Modules\Uploads\Providers;

use App\Modules\Uploads\Console\SweepUploadsCommand;
use App\Modules\Uploads\Contracts\UploadedSources;
use App\Modules\Uploads\Services\EloquentUploadedSources;
use Illuminate\Console\Scheduling\Schedule;
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

        // Abandoned and stuck uploads (design doc §10.5). Each session is handled idempotently, so
        // an overlapping or repeated run is harmless; withoutOverlapping just avoids wasted work.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(SweepUploadsCommand::class)->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
        });
    }
}
