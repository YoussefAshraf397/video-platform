<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\Auth;

uses(RefreshDatabase::class);

beforeEach(fn () => Mail::fake());

function loginFrom(string $ip, string $email, string $password): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/v1/auth/login', ['email' => $email, 'password' => $password]);
}

function assertRateLimited(TestResponse $response): void
{
    $response->assertStatus(429)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
}

it('limits login to 10 requests per minute per IP', function () {
    for ($i = 0; $i < 10; $i++) {
        loginFrom('203.0.113.1', "user{$i}@example.com", 'whatever password')->assertStatus(401);
    }

    assertRateLimited(loginFrom('203.0.113.1', 'another@example.com', 'whatever password'));
    loginFrom('203.0.113.2', 'another@example.com', 'whatever password')->assertStatus(401);   // other IPs unaffected

    $this->travel(61)->seconds();
    loginFrom('203.0.113.1', 'another@example.com', 'whatever password')->assertStatus(401);
});

it('locks an account after 5 failures from one IP, even for the right password', function () {
    Auth::createUser();
    for ($i = 0; $i < 5; $i++) {
        loginFrom('203.0.113.1', 'ada@example.com', 'wrong password')->assertStatus(401);
    }

    assertRateLimited(loginFrom('203.0.113.1', 'ada@example.com', Auth::PASSWORD));

    // The real owner, on their own IP, can still sign in.
    loginFrom('198.51.100.7', 'ada@example.com', Auth::PASSWORD)->assertOk();

    $this->travel(16)->minutes();
    loginFrom('203.0.113.1', 'ada@example.com', Auth::PASSWORD)->assertOk();
});

it('locks an account after 20 failures from any mix of IPs', function () {
    Auth::createUser();
    for ($i = 0; $i < 20; $i++) {
        loginFrom("203.0.113.{$i}", 'ada@example.com', 'wrong password')->assertStatus(401);
    }

    assertRateLimited(loginFrom('198.51.100.7', 'ada@example.com', Auth::PASSWORD));
});

it('locks unknown emails exactly like real ones', function () {
    Auth::createUser();
    foreach (['ada@example.com', 'nobody@example.com'] as $email) {
        for ($i = 0; $i < 5; $i++) {
            loginFrom('203.0.113.1', $email, 'wrong password');
        }
    }
    $this->travel(61)->seconds();   // past the per-IP request limit

    $real = loginFrom('203.0.113.1', 'ada@example.com', 'wrong password');
    $unknown = loginFrom('203.0.113.1', 'nobody@example.com', 'wrong password');

    assertRateLimited($real);
    assertRateLimited($unknown);
    expect($unknown->json('title'))->toBe($real->json('title'));
});

it('resets the failure count after a successful sign-in', function () {
    Auth::createUser();
    for ($i = 0; $i < 4; $i++) {
        loginFrom('203.0.113.1', 'ada@example.com', 'wrong password');
    }
    loginFrom('203.0.113.1', 'ada@example.com', Auth::PASSWORD)->assertOk();

    for ($i = 0; $i < 4; $i++) {
        loginFrom('203.0.113.1', 'ada@example.com', 'wrong password')->assertStatus(401);   // not locked
    }
});

it('limits registration to 5 per hour per IP', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/v1/auth/register', ['email' => "u{$i}@example.com", 'password' => 'a long enough password', 'display_name' => 'U'])
            ->assertStatus(202);
    }

    assertRateLimited($this->postJson('/v1/auth/register', ['email' => 'u6@example.com', 'password' => 'a long enough password', 'display_name' => 'U']));
});

it('limits reset emails to 3 per hour per address, and 5 per hour per IP', function () {
    Auth::createUser();
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/v1/auth/password/forgot', ['email' => 'ada@example.com'])->assertStatus(202);
    }
    assertRateLimited($this->postJson('/v1/auth/password/forgot', ['email' => 'ada@example.com']));

    // Rejected requests don't use up quota: 3 accepted so far from this IP, so two more fit.
    $this->postJson('/v1/auth/password/forgot', ['email' => 'other@example.com'])->assertStatus(202);
    $this->postJson('/v1/auth/password/forgot', ['email' => 'third@example.com'])->assertStatus(202);
    assertRateLimited($this->postJson('/v1/auth/password/forgot', ['email' => 'fourth@example.com']));
    Mail::assertQueuedCount(3);
});

it('shares the email limit between password reset and verification resend', function () {
    Auth::createUser('ada@example.com', verified: false);
    $this->postJson('/v1/auth/password/forgot', ['email' => 'ada@example.com'])->assertStatus(202);
    $this->postJson('/v1/auth/email/resend', ['email' => 'ada@example.com'])->assertStatus(202);
    $this->postJson('/v1/auth/password/forgot', ['email' => 'ada@example.com'])->assertStatus(202);

    assertRateLimited($this->postJson('/v1/auth/email/resend', ['email' => 'ada@example.com']));
});

it('ignores a forged X-Forwarded-For when no proxy is trusted', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
            ->postJson('/v1/auth/login', ['email' => "u{$i}@example.com", 'password' => 'whatever password'], ['X-Forwarded-For' => "198.51.100.{$i}"]);
    }

    assertRateLimited($this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->postJson('/v1/auth/login', ['email' => 'x@example.com', 'password' => 'whatever password'], ['X-Forwarded-For' => '198.51.100.99']));
});
