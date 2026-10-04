<?php

use App\Platform\Api\Http\Middleware\EnforceIdempotency;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\SampleController;

beforeEach(fn () => SampleController::routes());

function storeCalls(): int
{
    return (int) Cache::get('sample-store-calls', 0);
}

it('requires an Idempotency-Key header', function () {
    $this->postJson('/v1/samples', ['name' => 'a'])
        ->assertStatus(400)
        ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');

    expect(storeCalls())->toBe(0);
});

it('rejects a malformed key', function () {
    $this->postJson('/v1/samples', ['name' => 'a'], ['Idempotency-Key' => 'has space'])
        ->assertStatus(400)
        ->assertJsonPath('code', 'IDEMPOTENCY_KEY_INVALID');
});

it('replays the first response for a retry without running the handler again', function () {
    $first = $this->postJson('/v1/samples', ['name' => 'a'], ['Idempotency-Key' => 'key-1'])->assertCreated();
    $retry = $this->postJson('/v1/samples', ['name' => 'a'], ['Idempotency-Key' => 'key-1'])->assertCreated();

    expect(storeCalls())->toBe(1)
        ->and($retry->getContent())->toBe($first->getContent())
        ->and($retry->headers->get('Location'))->toBe($first->headers->get('Location'))
        ->and($retry->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($first->headers->has('Idempotent-Replayed'))->toBeFalse();
});

it('treats different keys as different requests', function () {
    $this->postJson('/v1/samples', ['name' => 'a'], ['Idempotency-Key' => 'key-1'])->assertCreated();
    $this->postJson('/v1/samples', ['name' => 'a'], ['Idempotency-Key' => 'key-2'])->assertCreated();

    expect(storeCalls())->toBe(2);
});

it('rejects reusing a key for a different request', function () {
    $this->postJson('/v1/samples', ['name' => 'a'], ['Idempotency-Key' => 'key-1'])->assertCreated();

    $this->postJson('/v1/samples', ['name' => 'b'], ['Idempotency-Key' => 'key-1'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

    expect(storeCalls())->toBe(1);
});

it('returns 409 while a request with the same key is still in flight', function () {
    $this->postJson('/v1/samples', ['name' => 'a'], ['Idempotency-Key' => 'key-1'])->assertCreated();
    $cacheKey = EnforceIdempotency::cacheKey('ip:127.0.0.1', 'key-1');
    Cache::put($cacheKey, [...Cache::get($cacheKey), 'state' => 'in_flight']);

    $this->postJson('/v1/samples', ['name' => 'a'], ['Idempotency-Key' => 'key-1'])
        ->assertStatus(409)
        ->assertHeader('Retry-After', '1')
        ->assertJsonPath('code', 'IDEMPOTENCY_REQUEST_IN_PROGRESS');
});

it('does not store failed attempts, so the client can retry them', function () {
    $this->postJson('/v1/samples', ['name' => ''], ['Idempotency-Key' => 'key-1'])->assertStatus(422);
    $this->postJson('/v1/samples', ['name' => 'explode'], ['Idempotency-Key' => 'key-2'])->assertStatus(500);
    $this->postJson('/v1/samples', ['name' => 'explode'], ['Idempotency-Key' => 'key-2'])->assertStatus(500);

    expect(storeCalls())->toBe(2);
});
