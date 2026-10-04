<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Mail\PasswordChangedMail;
use App\Modules\Auth\Mail\ResetPasswordMail;
use App\Modules\Users\Contracts\UserDirectory;
use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * "Forgot password": an emailed single-use link sets a new password and signs the account out
 * everywhere, so a stolen session can't outlive the reset.
 */
final class PasswordReset
{
    public function __construct(
        private readonly UserDirectory $users,
        private readonly Sessions $sessions,
        private readonly Revocations $revocations,
        private readonly int $ttlSeconds,
        private readonly string $resetUrl,
    ) {}

    /** Sends a reset link if the account exists and is active; callers can't tell which. */
    public function request(string $email): void
    {
        $user = $this->users->findByEmail($email);
        if ($user === null || ! $user->isActive()) {
            return;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        DB::table('password_reset_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => $user->id,
            'created_at' => now(),
            'expires_at' => now()->addSeconds($this->ttlSeconds),
        ]);
        Mail::to($user->email)->queue(new ResetPasswordMail($user->displayName, $this->resetUrl.$token));
    }

    public function reset(string $token, string $newPassword): void
    {
        $passwordHash = Hash::driver('argon2id')->make($newPassword);

        $user = DB::transaction(function () use ($token, $passwordHash) {
            $row = DB::table('password_reset_tokens')
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();
            $user = $row === null ? null : $this->users->find($row->user_id);

            if ($row === null || $row->used_at !== null || now()->gte($row->expires_at) || $user === null || ! $user->isActive()) {
                throw new ApiProblem(422, 'INVALID_RESET_TOKEN', 'This reset link is invalid or has expired',
                    'Request a new password reset email.');
            }

            DB::table('credentials')->where('user_id', $user->id)->update([
                'password_hash' => $passwordHash,
                'password_changed_at' => now(),
                'updated_at' => now(),
            ]);
            // This link and any other outstanding ones are spent.
            DB::table('password_reset_tokens')->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
            // Opening the emailed link proves the user controls the address.
            $this->users->markEmailVerified($user->id);
            $this->sessions->revokeAll($user->id, 'password_reset');

            return $user;
        });

        // Belt and braces: reject every access token issued before now, whatever its session.
        $this->revocations->revokeTokensIssuedBefore($user->id, now());
        Mail::to($user->email)->queue(new PasswordChangedMail($user->displayName));
        Log::info('Password reset completed; all sessions revoked', ['user_id' => $user->id]);
    }
}
