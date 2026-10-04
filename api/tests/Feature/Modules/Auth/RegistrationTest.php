<?php

use App\Modules\Auth\Mail\AccountAlreadyExistsMail;
use App\Modules\Auth\Mail\VerifyEmailMail;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Auth;

uses(RefreshDatabase::class);

beforeEach(fn () => Mail::fake());

function verificationToken(): string
{
    $token = null;
    Mail::assertQueued(VerifyEmailMail::class, function (VerifyEmailMail $mail) use (&$token) {
        $token = substr($mail->verifyUrl, strpos($mail->verifyUrl, 'token=') + 6);

        return true;
    });

    return (string) $token;
}

it('creates an unverified account, stores an Argon2id hash, and emails a verification link', function () {
    $this->postJson('/v1/auth/register', ['email' => ' Ada@Example.com ', 'password' => 'a long enough password', 'display_name' => 'Ada'])
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Check your email to continue.']);

    $user = User::where('email', 'ada@example.com')->firstOrFail();   // normalized
    expect($user->email_verified_at)->toBeNull()
        ->and(DB::table('credentials')->where('user_id', $user->id)->value('password_hash'))->toStartWith('$argon2id$');
    Mail::assertQueued(VerifyEmailMail::class, fn ($mail) => $mail->hasTo('ada@example.com')
        && str_starts_with($mail->verifyUrl, 'http://localhost:3000/verify-email?token='));
});

it('answers a registered email exactly like a new one, and emails the owner instead', function () {
    Auth::createUser('ada@example.com');
    $new = $this->postJson('/v1/auth/register', ['email' => 'new@example.com', 'password' => 'a long enough password', 'display_name' => 'New']);
    $taken = $this->postJson('/v1/auth/register', ['email' => 'ADA@example.com', 'password' => 'a long enough password', 'display_name' => 'Mallory']);

    expect($taken->status())->toBe($new->status())
        ->and($taken->json())->toBe($new->json())
        ->and(User::where('email', 'ada@example.com')->count())->toBe(1);
    Mail::assertQueued(AccountAlreadyExistsMail::class, fn ($mail) => $mail->hasTo('ada@example.com'));
});

it('validates registration input as problem details', function () {
    $this->postJson('/v1/auth/register', ['email' => 'not-an-email', 'password' => 'short', 'display_name' => ''])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('errors.*.field', ['email', 'password', 'display_name']);
});

it('verifies the email with the link token, once', function () {
    $this->postJson('/v1/auth/register', ['email' => 'ada@example.com', 'password' => 'a long enough password', 'display_name' => 'Ada']);
    $token = verificationToken();

    $this->postJson('/v1/auth/email/verify', ['token' => $token])->assertOk()->assertExactJson(['email_verified' => true]);
    expect(User::where('email', 'ada@example.com')->value('email_verified_at'))->not->toBeNull();

    $this->postJson('/v1/auth/email/verify', ['token' => $token])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_VERIFICATION_TOKEN');
});

it('rejects expired and unknown verification tokens with the same answer', function () {
    $this->postJson('/v1/auth/register', ['email' => 'ada@example.com', 'password' => 'a long enough password', 'display_name' => 'Ada']);
    $token = verificationToken();
    $this->travel(25)->hours();

    $expired = $this->postJson('/v1/auth/email/verify', ['token' => $token]);
    $unknown = $this->postJson('/v1/auth/email/verify', ['token' => 'nope']);

    expect($expired->json('code'))->toBe('INVALID_VERIFICATION_TOKEN')
        ->and($expired->json('title'))->toBe($unknown->json('title'));
});

it('resends verification only to unverified accounts, answering the same for every email', function () {
    Auth::createUser('unverified@example.com', verified: false);
    Auth::createUser('verified@example.com', verified: true);

    $responses = array_map(fn ($email) => $this->postJson('/v1/auth/email/resend', ['email' => $email]),
        ['unverified@example.com', 'verified@example.com', 'nobody@example.com']);

    foreach ($responses as $response) {
        $response->assertStatus(202)->assertExactJson($responses[0]->json());
    }
    Mail::assertQueuedCount(1);
    Mail::assertQueued(VerifyEmailMail::class, fn ($mail) => $mail->hasTo('unverified@example.com'));
});
