<?php

use App\Providers\AIServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BackupServiceProvider;
use App\Providers\ResearchServiceProvider;
use App\Providers\SocialServiceProvider;
use App\Providers\TrendingServiceProvider;

return [
    AIServiceProvider::class,
    AppServiceProvider::class,
    BackupServiceProvider::class,
    ResearchServiceProvider::class,
    SocialServiceProvider::class,
    TrendingServiceProvider::class,
];
