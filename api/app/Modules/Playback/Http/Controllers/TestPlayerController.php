<?php

namespace App\Modules\Playback\Http\Controllers;

use Illuminate\Http\Response;

/**
 * GET /dev/player (S4-07): an internal page for demos and QA until the web app exists. It calls the
 * playback API like a real client, lets the API set the signed cookies, plays the stream with
 * hls.js and shows rendition switching. Only when `playback.test_player` is on (local and dev).
 */
final class TestPlayerController
{
    public function __invoke(): Response
    {
        abort_unless(config('playback.test_player'), 404);

        return response()->view('playback::test-player')->header('X-Robots-Tag', 'noindex');
    }
}
