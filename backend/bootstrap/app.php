<?php

use App\Http\Middleware\EnsureUserIsAdmin;
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
        // never fires. runInBackground(): without it, schedule:work runs every due
        // command IN-PROCESS, so a single hung command (e.g. a network call that
        // never returns) freezes the whole scheduler — every other scheduled command
        // silently stops firing too, with no crash and no log entry to point at why.
        // Each command in its own OS process means one hanging never blocks another.
        $schedule->command('jobs:reap-stalled')->everyFiveMinutes()->onOneServer()->runInBackground();

        // See App\Console\Commands\PollChannelWatches. Every 15 min, not 5: YouTube's
        // default quota is 10,000 units/day and playlistItems.list costs 1 unit/channel/
        // poll, so even 50 watched channels stays at ~4,800 units/day, leaving headroom
        // for channel-resolution (channels.list) calls made from the UI. Same
        // schedule:work caveat as jobs:reap-stalled above. withoutOverlapping() because
        // a network-bound poll across many channels is more likely to run long than the
        // 5-minute reap job is; runInBackground() for the same reason as above.
        $schedule->command('channels:poll')->everyFifteenMinutes()->onOneServer()->withoutOverlapping()->runInBackground();

        // See App\Console\Commands\DailyContentResearch. Hourly rather than at a fixed
        // daily time on purpose: each content channel stores its own research times in
        // its own timezone (one channel at 06:00, another at 06:00/12:00/18:00/21:00),
        // so this tick only asks "who is due now" and the schedule itself stays in the
        // database, editable from the UI. Same schedule:work caveat as the two above.
        // The command only dispatches queued jobs, so it returns fast — but
        // withoutOverlapping() still guards against a slow database making two ticks
        // race and double-dispatch the same channel.
        $schedule->command('research:daily')->hourly()->onOneServer()->withoutOverlapping()->runInBackground();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
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
