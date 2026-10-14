<?php

/* Playback (design doc §13): where media is served from and how access to it is signed. */
return [
    // Origin players fetch media from: the CloudFront distribution (S4-03), e.g. https://media.dev.example.com.
    // Locally, the S3 emulator's media bucket (it doesn't check signatures).
    'media_base_url' => env('MEDIA_BASE_URL', 'http://localhost:4566/media'),

    // Parent domain the signed cookies are set on, so the API (api.example.com) can set cookies the
    // media domain (media.example.com) receives, e.g. ".example.com". Null: host-only (local dev).
    'cookie_domain' => env('MEDIA_COOKIE_DOMAIN'),

    // CloudFront public key id (in the distribution's trusted key group) and its private key,
    // base64-encoded PEM (Secrets Manager in AWS). Generate a pair with `php artisan playback:cdn-keys`.
    'key_pair_id' => env('CLOUDFRONT_KEY_PAIR_ID'),
    'private_key' => env('CLOUDFRONT_PRIVATE_KEY'),
];
