<?php

namespace App\Modules\Processing\Providers;

use Illuminate\Support\ServiceProvider;

final class ProcessingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
