<?php

namespace App\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // On this machine/network, outbound HTTPS requests that negotiate HTTP/2
        // hang indefinitely with no error (observed against api.openai.com — likely
        // local TLS-inspecting security software mishandling HTTP/2 framing).
        // HTTP/1.1 works instantly, so force it for every Http:: call app-wide.
        Http::globalOptions(['version' => 1.1]);
    }
}
