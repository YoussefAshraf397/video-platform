<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

it('generates a request ID and adds it to the response and log context', function () {
    $response = $this->get('/health/live');

    $id = $response->headers->get('X-Request-Id');
    expect(Str::isUuid($id))->toBeTrue()
        ->and(Context::get('request_id'))->toBe($id);
});

it('keeps a well-formed request ID sent by the caller', function () {
    $this->get('/health/live', ['X-Request-Id' => 'client-abc-12345'])
        ->assertHeader('X-Request-Id', 'client-abc-12345');
});

it('replaces a malformed request ID', function () {
    $response = $this->get('/health/live', ['X-Request-Id' => "bad\nid with spaces"]);

    expect(Str::isUuid($response->headers->get('X-Request-Id')))->toBeTrue();
});
