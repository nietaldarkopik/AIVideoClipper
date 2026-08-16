<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // See App\Console\Commands\ReapStalledProcessingJobs — needs `php artisan
        // schedule:work` actually running (start-all.ps1/.bat starts it) or this
        // never fires.
        $schedule->command('jobs:reap-stalled')->everyFiveMinutes()->onOneServer();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);

        // This app has no login page (routes/web.php is just the placeholder
        // welcome view). See the AuthenticationException render() override below
        // for the actual fix — this alone isn't enough, since Laravel's default
        // exception Handler::unauthenticated() falls back to route('login')
        // itself regardless of what's registered here.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Laravel's default unauthenticated() handler does
        // `redirect()->guest($exception->redirectTo() ?? route('login'))` for any
        // request that doesn't look like it expects JSON — and always throws
        // RouteNotFoundException here since this app has no 'login' route. Always
        // respond with a clean 401 JSON instead, since every route in this app is
        // an API route (routes/web.php is just the placeholder welcome view).
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });
    })->create();
