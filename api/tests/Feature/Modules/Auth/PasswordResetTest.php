<?php

use App\Modules\Auth\Mail\PasswordChangedMail;
use App\Modules\Auth\Mail\ResetPasswordMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Auth;

uses(RefreshDatabase::class);

beforeEach(fn () => Mail::fake());

const NEW_PASSWORD = 'a brand new password';

/** Requests a reset for $email and returns the token from the queued email. */
function requestReset(string $email = 'ada@example.com'): string
{
    test()->postJson('/v1/auth/password/forgot', ['email' => $email])->assertStatus(202);
    $token = null;
    Mail::assertQueued(ResetPasswordMail::class, function (ResetPasswordMail $mail) use (&$token) {
        $token = substr($mail->resetUrl, strpos($mail->resetUrl, 'token=') + 6);

        return true;
    });

    return (string) $token;
}

it('emails a reset link, answering the same whether or not the email has an account', function () {
    Auth::createUser('ada@example.com');
    Auth::createUser('banned@example.com', status: 'suspended');

    $responses = array_map(fn ($email) => $this->postJson('/v1/auth/password/forgot', ['email' => $email]),
        ['ada@example.com', 'nobody@example.com', 'banned@example.com']);

    foreach ($responses as $response) {
        $response->assertStatus(202)->assertExactJson($responses[0]->json());
    }
    Mail::assertQueuedCount(1);
    Mail::assertQueued(ResetPasswordMail::class, fn ($mail) => $mail->hasTo('ada@example.com')
        && str_starts_with($mail->resetUrl, 'http://localhost:3000/reset-password?token='));
});

it('sets the new password', function () {
    Auth::createUser();
    $token = requestReset();

    $this->postJson('/v1/auth/password/reset', ['token' => $token, 'password' => NEW_PASSWORD])->assertOk();

    $this->postJson('/v1/auth/login', ['email' => 'ada@example.com', 'password' => Auth::PASSWORD])->assertStatus(401);
    $this->postJson('/v1/auth/login', ['email' => 'ada@example.com', 'password' => NEW_PASSWORD])->assertOk();
    Mail::assertQueued(PasswordChangedMail::class, fn ($mail) => $mail->hasTo('ada@example.com'));
});

it('revokes every session: refresh tokens and still-unexpired access tokens stop working', function () {
    Auth::createUser();
    ['access_token' => $laptopAccess, 'refresh_token' => $laptopRefresh] = Auth::login($this, userAgent: 'laptop');
    ['access_token' => $phoneAccess, 'refresh_token' => $phoneRefresh] = Auth::login($this, userAgent: 'phone');

    $this->postJson('/v1/auth/password/reset', ['token' => requestReset(), 'password' => NEW_PASSWORD])->assertOk();

    foreach ([$laptopAccess, $phoneAccess] as $access) {
        $this->getJson('/v1/auth/sessions', ['Authorization' => "Bearer {$access}"])->assertStatus(401);
    }
    foreach ([$laptopRefresh, $phoneRefresh] as $refresh) {
        Auth::refresh($this, $refresh)->assertStatus(401);
    }
    expect(DB::table('auth_sessions')->pluck('revoke_reason')->unique()->all())->toBe(['password_reset']);
});

it('rejects access tokens issued before the reset, by issue time alone', function () {
    Auth::createUser();
    ['access_token' => $before] = Auth::login($this);
    $token = requestReset();
    $this->travel(1)->second();
    $this->postJson('/v1/auth/password/reset', ['token' => $token, 'password' => NEW_PASSWORD])->assertOk();

    // Undo the session revocation, leaving only the user's min_iat in force.
    $sessionId = DB::table('auth_sessions')->value('id');
    DB::table('auth_sessions')->update(['revoked_at' => null]);
    cache()->forget("auth:revoked-session:{$sessionId}");

    $this->getJson('/v1/auth/sessions', ['Authorization' => "Bearer {$before}"])->assertStatus(401);

    // Tokens issued after the reset work.
    $this->travel(1)->second();
    $after = $this->postJson('/v1/auth/login', ['email' => 'ada@example.com', 'password' => NEW_PASSWORD])->json('access_token');
    $this->getJson('/v1/auth/sessions', ['Authorization' => "Bearer {$after}"])->assertOk();
});

it('marks the email verified, since the link proved the user owns it', function () {
    $user = Auth::createUser(verified: false);

    $this->postJson('/v1/auth/password/reset', ['token' => requestReset(), 'password' => NEW_PASSWORD])->assertOk();

    expect(DB::table('users')->where('id', $user->id)->value('email_verified_at'))->not->toBeNull();
});

it('accepts each link once, and spends all other outstanding links', function () {
    Auth::createUser();
    $first = requestReset();
    $this->postJson('/v1/auth/password/forgot', ['email' => 'ada@example.com']);
    $tokens = [];
    Mail::assertQueued(ResetPasswordMail::class, function ($mail) use (&$tokens) {
        $tokens[] = substr($mail->resetUrl, strpos($mail->resetUrl, 'token=') + 6);

        return true;
    });
    $second = $tokens[1];

    $this->postJson('/v1/auth/password/reset', ['token' => $first, 'password' => NEW_PASSWORD])->assertOk();

    foreach ([$first, $second] as $spent) {
        $this->postJson('/v1/auth/password/reset', ['token' => $spent, 'password' => 'yet another password'])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_RESET_TOKEN');
    }
});

it('expires links after 60 minutes', function () {
    Auth::createUser();
    $token = requestReset();
    $this->travel(61)->minutes();

    $this->postJson('/v1/auth/password/reset', ['token' => $token, 'password' => NEW_PASSWORD])
        ->assertStatus(422)->assertJsonPath('code', 'INVALID_RESET_TOKEN');
});

it('validates the new password', function () {
    Auth::createUser();

    $this->postJson('/v1/auth/password/reset', ['token' => requestReset(), 'password' => 'short'])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'password');
});
