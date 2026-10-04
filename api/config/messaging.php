<?php

return [

    /*
     * `producer` field of every envelope this application publishes.
     */
    'producer' => 'api',

    'aws' => [
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        // Only set locally (AWS emulator). Unset in AWS environments.
        'endpoint' => env('AWS_ENDPOINT_URL'),
    ],

    /*
     * Topic ARN = prefix + topic name, e.g. "arn:aws:sns:us-east-1:123456789012:" + "video-events".
     */
    'sns_topic_arn_prefix' => env('SNS_TOPIC_ARN_PREFIX'),

    /*
     * IdempotentConsumer classes that `php artisan messages:consume {name}` can run.
     * Each one reads the SQS queue named after its name().
     */
    'consumers' => [
    ],

];
