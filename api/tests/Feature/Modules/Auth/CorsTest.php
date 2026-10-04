<?php

it('lets the frontend call the API with credentials', function () {
    $this->call('OPTIONS', '/v1/auth/refresh', server: [
        'HTTP_ORIGIN' => 'http://localhost:3000',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

it('gives other sites no CORS access', function () {
    $response = $this->call('OPTIONS', '/v1/auth/refresh', server: [
        'HTTP_ORIGIN' => 'https://evil.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    // The browser enforces CORS: access is granted only if this header names the caller's origin.
    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example');
});
