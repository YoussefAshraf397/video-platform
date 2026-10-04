<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Mail\AccountAlreadyExistsMail;
use App\Modules\Auth\Mail\VerifyEmailMail;
use App\Modules\Users\Contracts\UserDirectory;
use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Sign-up and email verification, without revealing which emails are registered: callers get
 * the same response either way, and the difference is only visible in the mailbox.
 */
final class Registration
{
    public function __construct(
        private readonly UserDirectory $users,
        private readonly int $verificationTtlSeconds,
        private readonly string $verificationUrl,
    ) {}

    public function register(string $email, string $password, string $displayName): void
    {
        // Hash first, on every path, so response time doesn't reveal whether the email exists.
        $passwordHash = Hash::driver('argon2id')->make($password);

        DB::transaction(function () use ($email, $passwordHash, $displayName) {
            $user = $this->users->create($email, $displayName);
            if ($user === null) {
                $existing = $this->users->findByEmail($email);
                if ($existing !== null) {
                    Mail::to($existing->email)->queue(new AccountAlreadyExistsMail($existing->displayName));
                }

                return;
            }

            DB::table('credentials')->insert([
                'user_id' => $user->id,
                'password_hash' => $passwordHash,
                'password_changed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->sendVerification($user->id, $user->email, $user->displayName);
        });
    }

    public function resendVerification(string $email): void
    {
        $user = $this->users->findByEmail($email);
        if ($user !== null && ! $user->isEmailVerified() && $user->isActive()) {
            DB::transaction(fn () => $this->sendVerification($user->id, $user->email, $user->displayName));
        }
    }

    public function verifyEmail(string $token): void
    {
        DB::transaction(function () use ($token) {
            $row = DB::table('email_verification_tokens')
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->used_at !== null || now()->gte($row->expires_at)) {
                // One generic answer: don't tell an attacker which of the three it was.
                throw new ApiProblem(422, 'INVALID_VERIFICATION_TOKEN', 'This verification link is invalid or has expired',
                    'Request a new verification email.');
            }

            DB::table('email_verification_tokens')->where('token_hash', $row->token_hash)->update(['used_at' => now()]);
            $this->users->markEmailVerified($row->user_id);
        });
    }

    private function sendVerification(string $userId, string $email, string $displayName): void
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        DB::table('email_verification_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
            'created_at' => now(),
            'expires_at' => now()->addSeconds($this->verificationTtlSeconds),
        ]);
        Mail::to($email)->queue(new VerifyEmailMail($displayName, $this->verificationUrl.$token));
    }
}
