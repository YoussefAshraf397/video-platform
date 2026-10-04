<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Auth;

uses(RefreshDatabase::class);

beforeEach(fn () => Auth::createUser());

function bearer(string $token): array
{
    return ['Authorization' => "Bearer {$token}"];
}

it('logs out: the access token, the refresh token and the cookie stop working at once', function () {
    ['access_token' => $access, 'refresh_token' => $refresh] = Auth::login($this);

    $response = $this->postJson('/v1/auth/logout', [], bearer($access))->assertNoContent();
    expect($response->headers->get('Set-Cookie'))->toContain('refresh_token=deleted')->toContain('Max-Age=0');

    $this->getJson('/v1/auth/sessions', bearer($access))->assertStatus(401);
    Auth::refresh($this, $refresh)->assertStatus(401);
});

it('logs out of every device', function () {
    ['access_token' => $laptop] = Auth::login($this, userAgent: 'laptop');
    ['access_token' => $phone, 'refresh_token' => $phoneRefresh] = Auth::login($this, userAgent: 'phone');

    $this->postJson('/v1/auth/logout-all', [], bearer($laptop))->assertNoContent();

    $this->getJson('/v1/auth/sessions', bearer($phone))->assertStatus(401);
    Auth::refresh($this, $phoneRefresh)->assertStatus(401);
});

it('lists active sessions and marks the current one', function () {
    Auth::login($this, userAgent: 'phone');
    ['access_token' => $laptop] = Auth::login($this, userAgent: 'laptop');

    $items = $this->getJson('/v1/auth/sessions', bearer($laptop))->assertOk()->json('items');

    expect($items)->toHaveCount(2)
        ->and(collect($items)->firstWhere('current', true)['user_agent'])->toBe('laptop')
        ->and(collect($items)->firstWhere('current', false)['user_agent'])->toBe('phone');
});

it('revokes one of your other sessions', function () {
    ['refresh_token' => $phoneRefresh] = Auth::login($this, userAgent: 'phone');
    ['access_token' => $laptop] = Auth::login($this, userAgent: 'laptop');
    $phoneId = collect($this->getJson('/v1/auth/sessions', bearer($laptop))->json('items'))->firstWhere('current', false)['id'];

    $this->deleteJson("/v1/auth/sessions/{$phoneId}", [], bearer($laptop))->assertNoContent();

    Auth::refresh($this, $phoneRefresh)->assertStatus(401);
    expect($this->getJson('/v1/auth/sessions', bearer($laptop))->json('items'))->toHaveCount(1);
});

it("returns 404 for someone else's session", function () {
    Auth::createUser('eve@example.com');
    ['access_token' => $eve] = Auth::login($this, 'eve@example.com');
    ['access_token' => $ada] = Auth::login($this);
    $adaSession = $this->getJson('/v1/auth/sessions', bearer($ada))->json('items.0.id');

    $this->deleteJson("/v1/auth/sessions/{$adaSession}", [], bearer($eve))
        ->assertStatus(404)->assertJsonPath('code', 'SESSION_NOT_FOUND');
    $this->getJson('/v1/auth/sessions', bearer($ada))->assertOk();
});
