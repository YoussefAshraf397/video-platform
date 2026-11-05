<?php

namespace App\Modules\Processing\Providers;

use App\Modules\Processing\Contracts\PlayableMedia;
use App\Modules\Processing\Services\CaptionPublisher;
use App\Modules\Processing\Services\LatestPlayableMedia;
use App\Modules\Videos\Events\SubtitlesChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class ProcessingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PlayableMedia::class, LatestPlayableMedia::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
        Event::listen(SubtitlesChanged::class, [CaptionPublisher::class, 'onSubtitlesChanged']);
    }
}
