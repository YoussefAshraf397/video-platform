<?php

/* S4-07: the internal hls.js test player at /dev/player. */

it('does not exist unless enabled', function () {
    config(['playback.test_player' => false]);

    $this->get('/dev/player')->assertNotFound();
});

it('serves the player page when enabled', function () {
    config(['playback.test_player' => true]);

    $this->get('/dev/player?v=y4S2byb8ju0')->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertSee('Test player', false)
        // hls.js is pinned and integrity-checked, not "latest" from a CDN.
        ->assertSee('src="https://cdn.jsdelivr.net/npm/hls.js@1.7.4/dist/hls.min.js"', false)
        ->assertSee('integrity="sha384-p9vgU/7IYJWd3I4STrtqxqR/2cAQlhIX7D/HrTnIMEjjcUox165b7se7sxmm3zDg"', false)
        ->assertSee('crossorigin="anonymous"', false);
});
