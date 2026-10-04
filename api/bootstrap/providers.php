<?php

use App\Modules\Auth\Providers\AuthServiceProvider;
use App\Modules\Health\Providers\HealthServiceProvider;
use App\Modules\Users\Providers\UsersServiceProvider;
use App\Platform\Messaging\MessagingServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    MessagingServiceProvider::class,
    HealthServiceProvider::class,
    UsersServiceProvider::class,
    AuthServiceProvider::class,
];
