<?php

namespace App\Providers;

use App\Services\Social\SocialProviderManager;
use Illuminate\Support\ServiceProvider;

class SocialServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SocialProviderManager::class);
    }
}
