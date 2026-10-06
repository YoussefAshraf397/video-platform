<?php

use App\Modules\Auth\Providers\AuthServiceProvider;
use App\Modules\Health\Providers\HealthServiceProvider;
use App\Modules\Processing\Providers\ProcessingServiceProvider;
use App\Modules\Uploads\Providers\UploadsServiceProvider;
use App\Modules\Users\Providers\UsersServiceProvider;
use App\Modules\Videos\Providers\VideosServiceProvider;
use App\Platform\Aws\AwsServiceProvider;
use App\Platform\Messaging\MessagingServiceProvider;
use App\Platform\Observability\ObservabilityServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    ObservabilityServiceProvider::class,
    AwsServiceProvider::class,
    MessagingServiceProvider::class,
    HealthServiceProvider::class,
    UsersServiceProvider::class,
    AuthServiceProvider::class,
    VideosServiceProvider::class,
    UploadsServiceProvider::class,
    ProcessingServiceProvider::class,
];
