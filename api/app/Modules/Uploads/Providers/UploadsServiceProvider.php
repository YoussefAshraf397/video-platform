<?php

namespace App\Modules\Uploads\Providers;

use Illuminate\Support\ServiceProvider;

final class UploadsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
