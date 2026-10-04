<?php

use App\Modules\Auth\Services\AccessTokens;
use App\Modules\Auth\Services\LaravelClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Auth;

uses(RefreshDatabase::class);

beforeEach(fn () => Auth::createUser());

function sessionsWith(string $authorization)
{
    return test()->getJson('/v1/auth/sessions', ['Authorization' => $authorization]);
}

it('requires a token on protected endpoints', function () {
    $this->getJson('/v1/auth/sessions')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    $this->get('/v1/auth/sessions')->assertStatus(401);   // no Accept header: still a JSON problem, no redirect
});

it('rejects expired access tokens (after the 30 s leeway)', function () {
    ['access_token' => $jwt] = Auth::login($this);

    $this->travel(15 * 60 + 20)->seconds();
    sessionsWith("Bearer {$jwt}")->assertOk();
    $this->travel(15)->seconds();
    sessionsWith("Bearer {$jwt}")->assertStatus(401);
});

it('rejects tampered, foreign-key and unsigned tokens', function () {
    ['access_token' => $jwt] = Auth::login($this);
    [$header, $payload, $signature] = explode('.', $jwt);
    $b64 = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
    $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

    // Payload changed to another user, original signature kept.
    $tampered = $header.'.'.$b64([...$claims, 'sub' => '00000000-0000-7000-8000-000000000000']).'.'.$signature;
    sessionsWith("Bearer {$tampered}")->assertStatus(401);

    // alg "none" with no signature.
    $unsigned = $b64(['alg' => 'none', 'typ' => 'JWT', 'kid' => 'test-key']).'.'.$payload.'.';
    sessionsWith("Bearer {$unsigned}")->assertStatus(401);

    // Validly signed, with the trusted key id, but by a key this API doesn't trust.
    $pair = sodium_crypto_sign_keypair();
    $foreignKey = ['id' => 'test-key', 'private' => base64_encode(sodium_crypto_sign_secretkey($pair)), 'public' => base64_encode(sodium_crypto_sign_publickey($pair))];
    $foreign = AccessTokens::fromConfig([...config('auth.tokens'), 'signing_key' => $foreignKey], new LaravelClock)
        ->issue($claims['sub'], $claims['sid'], true);
    sessionsWith("Bearer {$foreign}")->assertStatus(401);

    sessionsWith('Bearer not.a.jwt')->assertStatus(401);
    sessionsWith("Basic {$jwt}")->assertStatus(401);
});

it('keeps accepting tokens signed with the previous key during a key rotation', function () {
    ['access_token' => $oldJwt] = Auth::login($this);
    $old = config('auth.tokens.signing_key');
    $pair = sodium_crypto_sign_keypair();
    config([
        'auth.tokens.signing_key' => ['id' => 'new-key', 'private' => base64_encode(sodium_crypto_sign_secretkey($pair)), 'public' => base64_encode(sodium_crypto_sign_publickey($pair))],
        'auth.tokens.previous_key' => ['id' => $old['id'], 'public' => $old['public']],
    ]);
    app()->forgetInstance(AccessTokens::class);

    sessionsWith("Bearer {$oldJwt}")->assertOk();
    ['access_token' => $newJwt] = Auth::login($this);
    sessionsWith("Bearer {$newJwt}")->assertOk();

    config(['auth.tokens.previous_key' => ['id' => null, 'public' => null]]);
    app()->forgetInstance(AccessTokens::class);
    sessionsWith("Bearer {$oldJwt}")->assertStatus(401);   // old key retired
});
