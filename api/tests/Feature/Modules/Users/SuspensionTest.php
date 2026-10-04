<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    Auth::createUser('ada@example.com')->update(['handle' => 'ada']);
    ['access_token' => $this->access, 'refresh_token' => $this->refresh] = Auth::login($this, userAgent: 'laptop');
    ['access_token' => $this->phoneAccess] = Auth::login($this, userAgent: 'phone');
});

it("rejects a suspended user's existing access tokens immediately, on every device", function () {
    $this->artisan('users:suspend', ['email' => 'ada@example.com', '--reason' => 'case-123'])
        ->expectsOutput('Suspended; all sessions revoked.')
        ->assertSuccessful();

    foreach ([$this->access, $this->phoneAccess] as $token) {
        $this->getJson('/v1/me', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }
    Auth::refresh($this, $this->refresh)->assertStatus(401);
    expect(DB::table('auth_sessions')->pluck('revoke_reason')->unique()->all())->toBe(['account_suspended']);
});

it('blocks sign-in and hides the profile while suspended', function () {
    $this->artisan('users:suspend', ['email' => 'ada@example.com']);

    $this->postJson('/v1/auth/login', ['email' => 'ada@example.com', 'password' => Auth::PASSWORD])
        ->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_DISABLED');
    $this->getJson('/v1/users/ada')->assertStatus(404);
});

it('allows signing in again after reactivation, while old tokens stay revoked', function () {
    $this->artisan('users:suspend', ['email' => 'ada@example.com']);
    $this->travel(1)->second();

    $this->artisan('users:suspend', ['email' => 'ada@example.com', '--undo' => true])
        ->expectsOutput('Reactivated.')->assertSuccessful();

    $this->getJson('/v1/me', ['Authorization' => "Bearer {$this->access}"])->assertStatus(401);
    $fresh = $this->postJson('/v1/auth/login', ['email' => 'ada@example.com', 'password' => Auth::PASSWORD])->assertOk()->json('access_token');
    $this->getJson('/v1/me', ['Authorization' => "Bearer {$fresh}"])->assertOk();
    $this->getJson('/v1/users/ada')->assertOk();
});

it('does not suspend twice or reactivate an active account', function () {
    $this->artisan('users:suspend', ['email' => 'ada@example.com']);

    $this->artisan('users:suspend', ['email' => 'ada@example.com'])->expectsOutput('Account was not active.');
    $this->artisan('users:suspend', ['email' => 'ada@example.com', '--undo' => true]);
    $this->artisan('users:suspend', ['email' => 'ada@example.com', '--undo' => true])->expectsOutput('Account was not suspended.');
});

it('reports unknown emails', function () {
    $this->artisan('users:suspend', ['email' => 'nobody@example.com'])
        ->expectsOutput('No account with that email.')
        ->assertFailed();
});
