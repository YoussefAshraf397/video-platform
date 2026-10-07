<?php

namespace App\Modules\Videos\Providers;

use App\Modules\Videos\Contracts\VideoDirectory;
use App\Modules\Videos\Contracts\VideoLifecycle;
use App\Modules\Videos\Contracts\VideoMedia;
use App\Modules\Videos\Services\ProcessedMedia;
use App\Modules\Videos\Services\Videos;
use App\Modules\Videos\Services\VideoStateMachine;
use Illuminate\Support\ServiceProvider;

final class VideosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VideoLifecycle::class, VideoStateMachine::class);
        $this->app->singleton(Videos::class);
        $this->app->singleton(VideoDirectory::class, Videos::class);
        $this->app->singleton(VideoMedia::class, ProcessedMedia::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
