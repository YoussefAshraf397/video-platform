<?php

/*
 * Browsers may call the API only from the platform's own frontends. Credentials (the refresh
 * cookie) are allowed, which is why origins must be listed explicitly, never "*".
 */
return [

    'paths' => ['v1/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Same list as the refresh endpoint's Origin check (config/auth.php).
    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('FRONTEND_ORIGINS', 'http://localhost:3000')))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key', 'If-Match', 'X-Request-Id'],

    'exposed_headers' => ['X-Request-Id', 'ETag', 'Location', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'Idempotent-Replayed'],

    'max_age' => 600,

    'supports_credentials' => true,

];
