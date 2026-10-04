<?php

/*
 * AWS SDK clients shared by every module (App\Platform\Aws\AwsServiceProvider).
 * In AWS, leave key, secret and endpoint unset: the SDK uses the ECS task role.
 */
return [
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    // Only set locally (AWS emulator).
    'endpoint' => env('AWS_ENDPOINT_URL'),
    // The emulator serves buckets by path (http://host/bucket/key), not by subdomain.
    'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
];
