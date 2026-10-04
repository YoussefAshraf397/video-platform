<?php

use App\Modules\Health\Providers\HealthServiceProvider;
use App\Platform\Messaging\MessagingServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    MessagingServiceProvider::class,
    HealthServiceProvider::class,
];
