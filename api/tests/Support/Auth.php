<?php

namespace Tests\Support;

use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Shortcuts for tests that need a signed-in user. */
final class Auth
{
    public const PASSWORD = 'correct horse battery staple';

    public static function createUser(string $email = 'ada@example.com', bool $verified = true, string $status = 'active'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'status' => $status,
            'email_verified_at' => $verified ? now() : null,
        ]);
        DB::table('credentials')->insert([
            'user_id' => $user->id,
            'password_hash' => Hash::driver('argon2id')->make(self::PASSWORD),
            'password_changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /** @return array{access_token: string, refresh_token: string, response: TestResponse} */
    public static function login(TestCase $test, string $email = 'ada@example.com', string $userAgent = 'test-agent'): array
    {
        $response = $test->postJson('/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD], ['User-Agent' => $userAgent])
            ->assertOk();

        return [
            'access_token' => $response->json('access_token'),
            'refresh_token' => self::refreshCookie($response),
            'response' => $response,
        ];
    }

    public static function refresh(TestCase $test, string $refreshToken, array $headers = []): TestResponse
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $test->call('POST', '/v1/auth/refresh', [], ['refresh_token' => $refreshToken], [], $server);
    }

    public static function refreshCookie(TestResponse $response): string
    {
        return (string) $response->getCookie('refresh_token', decrypt: false)?->getValue();
    }
}
