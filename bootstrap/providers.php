<?php

use App\Modules\Media\Providers\MediaServiceProvider;
use App\Modules\Notifications\Providers\NotificationsServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    NotificationsServiceProvider::class,
    MediaServiceProvider::class,
];
