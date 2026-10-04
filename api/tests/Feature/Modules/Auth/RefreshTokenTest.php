<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Auth;

uses(RefreshDatabase::class);

beforeEach(fn () => Auth::createUser());

it('rotates the refresh token and issues a new access token', function () {
    ['refresh_token' => $first] = Auth::login($this);

    $response = Auth::refresh($this, $first)->assertOk()->assertJsonStructure(['access_token', 'token_type', 'expires_in']);
    $second = Auth::refreshCookie($response);

    expect($second)->toStartWith('rt_')->not->toBe($first);
    Auth::refresh($this, $second)->assertOk();
});

it('revokes the whole session when an already-used refresh token is presented again', function () {
    ['refresh_token' => $stolen] = Auth::login($this);
    $legit = Auth::refreshCookie(Auth::refresh($this, $stolen)->assertOk());
    $this->travel(1)->minute();   // past the reuse grace window

    // The attacker replays the old token: refused, and the session is burned.
    Auth::refresh($this, $stolen)->assertStatus(401)->assertJsonPath('code', 'INVALID_REFRESH_TOKEN');

    // So the legitimate user's current token stops working too: the whole family is revoked.
    Auth::refresh($this, $legit)->assertStatus(401);
    expect(DB::table('auth_sessions')->value('revoke_reason'))->toBe('refresh_token_reuse');
});

it('also invalidates access tokens of a session revoked for reuse', function () {
    ['refresh_token' => $stolen] = Auth::login($this);
    $access = Auth::refresh($this, $stolen)->json('access_token');
    $this->travel(1)->minute();
    Auth::refresh($this, $stolen)->assertStatus(401);

    $this->getJson('/v1/auth/sessions', ['Authorization' => "Bearer {$access}"])->assertStatus(401);
});

it('does not revoke the session when two tabs refresh at the same moment', function () {
    ['refresh_token' => $token] = Auth::login($this);
    $winner = Auth::refreshCookie(Auth::refresh($this, $token)->assertOk());

    Auth::refresh($this, $token)->assertStatus(409)->assertJsonPath('code', 'REFRESH_TOKEN_ALREADY_ROTATED');

    Auth::refresh($this, $winner)->assertOk();   // session still alive
});

it('rejects missing, unknown and expired refresh tokens, and clears the cookie', function () {
    ['refresh_token' => $token] = Auth::login($this);

    $this->postJson('/v1/auth/refresh')->assertStatus(401);
    $unknown = Auth::refresh($this, 'rt_unknown')->assertStatus(401);
    expect($unknown->headers->get('Set-Cookie'))->toContain('refresh_token=deleted')->toContain('Max-Age=0');

    $this->travel(31)->days();
    Auth::refresh($this, $token)->assertStatus(401);
});

it('never extends a session beyond its absolute 90-day lifetime', function () {
    ['refresh_token' => $token] = Auth::login($this);
    for ($day = 0; $day < 3; $day++) {   // keep refreshing within each 30-day window...
        $this->travel(29)->days();
        $token = Auth::refreshCookie(Auth::refresh($this, $token)->assertOk());
    }
    $this->travel(4)->days();   // ...day 91

    Auth::refresh($this, $token)->assertStatus(401);
});

it('refuses refresh requests sent from another site', function () {
    ['refresh_token' => $token] = Auth::login($this);

    Auth::refresh($this, $token, ['Origin' => 'https://evil.example'])
        ->assertStatus(403)->assertJsonPath('code', 'ORIGIN_NOT_ALLOWED');
    Auth::refresh($this, $token, ['Origin' => 'http://localhost:3000'])->assertOk();
});

it('ends the session at refresh time if the account was disabled', function () {
    ['refresh_token' => $token] = Auth::login($this);
    DB::table('users')->update(['status' => 'suspended']);

    Auth::refresh($this, $token)->assertStatus(401);
    expect(DB::table('auth_sessions')->value('revoke_reason'))->toBe('account_disabled');
});
