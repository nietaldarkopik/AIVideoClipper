<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
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

        // Credential endpoints: keyed by IP *and* email so one address can't spray
        // many accounts, and one account can't be hammered from many addresses.
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by('auth-email:'.strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('auth-ip:'.$request->ip()),
        ]);

        // Baseline for every authenticated API call.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)
            ->by($request->user()?->id ?: $request->ip()));

        // Endpoints that start FFmpeg/yt-dlp/AI work (real money and CPU per call).
        RateLimiter::for('expensive', fn (Request $request) => Limit::perMinute(20)
            ->by('expensive:'.($request->user()?->id ?: $request->ip())));
    }
}
