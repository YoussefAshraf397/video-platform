<?php

namespace App\Modules\Playback\Providers;

use App\Modules\Playback\Console\GenerateCdnKeysCommand;
use App\Platform\Api\Http\CallerId;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class PlaybackServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
        $this->commands([GenerateCdnKeysCommand::class]);

        // Design doc §24.3: per IP (anonymous viewers), per user, and per viewer and video, which
        // stops one client farming cookies for a single video. A normal player asks once per
        // cookie lifetime (1-6 h) plus a few refreshes.
        RateLimiter::for('playback', function (Request $request) {
            $viewer = CallerId::tryFrom($request);
            $video = (string) $request->route('video');

            return array_filter([
                Limit::perMinute(300)->by('ip:'.$request->ip()),
                $viewer === null ? null : Limit::perMinute(300)->by('user:'.$viewer),
                Limit::perMinute(30)->by("video:{$video}:".($viewer ?? 'ip:'.$request->ip())),
            ]);
        });
    }
}
