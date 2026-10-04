<?php

use App\Modules\Auth\Services\AccessTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Auth;

uses(RefreshDatabase::class);

it('returns a 15-minute access token and sets the refresh token as a locked-down cookie', function () {
    $user = Auth::createUser();
    ['response' => $response, 'access_token' => $jwt] = Auth::login($this);

    $response->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('expires_in', 900)
        ->assertJsonPath('user', ['id' => $user->id, 'email' => 'ada@example.com', 'display_name' => $user->display_name, 'email_verified' => true])
        ->assertJsonMissingPath('refresh_token');   // never readable by scripts

    $claims = app(AccessTokens::class)->verify($jwt);
    expect($claims->userId)->toBe($user->id)
        ->and($claims->emailVerified)->toBeTrue();

    [$header] = explode('.', $jwt);
    expect(json_decode(base64_decode(strtr($header, '-_', '+/')), true))->toMatchArray(['alg' => 'EdDSA', 'kid' => 'test-key']);

    $cookie = $response->getCookie('refresh_token', decrypt: false);
    expect($cookie->getValue())->toStartWith('rt_')
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('strict')
        ->and($cookie->getPath())->toBe('/v1/auth');
});

it('stores only a hash of the refresh token', function () {
    Auth::createUser();
    ['refresh_token' => $token] = Auth::login($this);

    expect(DB::table('refresh_tokens')->where('token_hash', hash('sha256', $token))->exists())->toBeTrue()
        ->and(DB::table('refresh_tokens')->where('token_hash', $token)->exists())->toBeFalse();
});

it('gives the same answer for a wrong password and an unknown email', function () {
    Auth::createUser();

    $wrongPassword = $this->postJson('/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'wrong password']);
    $unknownEmail = $this->postJson('/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong password']);

    $wrongPassword->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
    expect(collect($unknownEmail->json())->except('request_id')->all())
        ->toBe(collect($wrongPassword->json())->except('request_id')->all());
    expect($unknownEmail->headers->has('Set-Cookie'))->toBeFalse();
});

it('accepts the email in any case', function () {
    Auth::createUser('ada@example.com');

    $this->postJson('/v1/auth/login', ['email' => 'ADA@Example.COM', 'password' => Auth::PASSWORD])->assertOk();
});

it('lets unverified users sign in, with email_verified false in the token', function () {
    Auth::createUser(verified: false);
    ['access_token' => $jwt] = Auth::login($this);

    expect(app(AccessTokens::class)->verify($jwt)->emailVerified)->toBeFalse();
});

it('refuses disabled accounts only after a correct password', function () {
    Auth::createUser(status: 'suspended');

    $this->postJson('/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'wrong password'])
        ->assertStatus(401);
    $this->postJson('/v1/auth/login', ['email' => 'ada@example.com', 'password' => Auth::PASSWORD])
        ->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_DISABLED');
});
