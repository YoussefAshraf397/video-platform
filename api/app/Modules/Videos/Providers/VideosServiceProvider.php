<?php

namespace App\Modules\Videos\Providers;

use App\Modules\Videos\Services\Videos;
use Illuminate\Support\ServiceProvider;

final class VideosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Videos::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
