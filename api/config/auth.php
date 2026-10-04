<?php

/*
 * Authentication is token-based (design doc §23): short-lived JWT access tokens plus rotating
 * refresh tokens, implemented in app/Modules/Auth. There are no server-side web sessions.
 */
return [

    'defaults' => [
        'guard' => 'api',
    ],

    'guards' => [
        'api' => [
            'driver' => 'jwt',   // registered by App\Modules\Auth\Providers\AuthServiceProvider
        ],
    ],

    'tokens' => [
        'issuer' => env('APP_URL', 'http://localhost'),
        'audience' => 'video-platform-api',

        'access_ttl_seconds' => 15 * 60,
        // Refresh tokens slide: each refresh issues one valid for another 30 days, until the
        // session's absolute limit.
        'refresh_ttl_seconds' => 30 * 24 * 3600,
        'session_max_lifetime_seconds' => 90 * 24 * 3600,
        // A refresh token used again within this window (two tabs refreshing at once) is
        // rejected without revoking the session; later reuse is treated as theft.
        'reuse_grace_seconds' => 20,

        // Ed25519 keys, base64-encoded (generate with `php artisan auth:jwt-keys`). The previous
        // public key keeps tokens signed before a key rotation valid until they expire.
        'signing_key' => [
            'id' => env('JWT_KEY_ID'),
            'private' => env('JWT_PRIVATE_KEY'),
            'public' => env('JWT_PUBLIC_KEY'),
        ],
        'previous_key' => [
            'id' => env('JWT_PREVIOUS_KEY_ID'),
            'public' => env('JWT_PREVIOUS_PUBLIC_KEY'),
        ],

        // Browsers may call cookie-authenticated endpoints (refresh) only from these origins.
        'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('FRONTEND_ORIGINS', 'http://localhost:3000')))),
        'refresh_cookie' => 'refresh_token',
    ],

    'password_reset' => [
        'ttl_seconds' => 60 * 60,
        'url' => env('FRONTEND_URL', 'http://localhost:3000').'/reset-password?token=',
    ],

    'email_verification' => [
        'ttl_seconds' => 24 * 3600,
        // Link in the verification email; the frontend posts the token to /v1/auth/email/verify.
        'url' => env('FRONTEND_URL', 'http://localhost:3000').'/verify-email?token=',
    ],

];
