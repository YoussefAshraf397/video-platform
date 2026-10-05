<?php

use App\Modules\Processing\Consumers\MediaDispatcher;
use App\Modules\Uploads\Consumers\UploadObjectCreatedConsumer;

return [

    /*
     * `producer` field of every envelope this application publishes.
     */
    'producer' => 'api',

    /*
     * Topic ARN = prefix + topic name, e.g. "arn:aws:sns:us-east-1:123456789012:" + "video-events".
     */
    'sns_topic_arn_prefix' => env('SNS_TOPIC_ARN_PREFIX'),

    /*
     * IdempotentConsumer classes that `php artisan messages:consume {name}` can run.
     * Each one reads the SQS queue named after its name().
     */
    'consumers' => [
        UploadObjectCreatedConsumer::class,
        MediaDispatcher::class,
    ],

];
