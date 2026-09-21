<?php

namespace App\Console\Commands;

use App\Jobs\RenderClipJob;
use App\Models\Clip;
use App\Models\ProcessingJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-dispatches RenderClipJob for clips whose render job never actually ran.
 *
 * Distinct from jobs:reap-stalled, which only notices a ProcessingJob that
 * reached STATUS_RUNNING and then went quiet — RenderClipJob creates its
 * ProcessingJob row itself, INSIDE handle(), so a dispatch that never gets
 * picked up (the queue driver drops it, e.g. a Redis restart with no AOF
 * persistence wiping jobs that were never flushed to an RDB snapshot) leaves no
 * running row for reap-stalled to find at all — the clip just sits at
 * queued/rendering with progress frozen wherever the last successful attempt
 * left it, forever, with nothing to notice.
 *
 * A clip is "stuck" here when it's been at queued/rendering for longer than the
 * stall threshold AND has no ProcessingJob currently tracking an attempt — the
 * second condition is what keeps this from redispatching a render that is
 * genuinely in progress (a multi-minute render legitimately sits at
 * queued/rendering with a live, RUNNING ProcessingJob updating it throughout).
 *
 * Scheduled every 10 minutes from bootstrap/app.php; requires `php artisan
 * schedule:work` to actually be running, same caveat as jobs:reap-stalled.
 */
class ReapStuckClipRenders extends Command
{
    protected $signature = 'clips:reap-stuck-renders {--minutes= : Override the stall threshold from config}';

    protected $description = 'Re-dispatch RenderClipJob for clips whose render job was lost before it ever ran';

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes') ?? config('services.processing.stall_minutes', 20));

        $stuck = Clip::query()
            ->whereIn('status', [Clip::STATUS_QUEUED, Clip::STATUS_RENDERING])
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->whereDoesntHave('processingJobs', fn ($q) => $q
                ->where('type', 'render_clip')
                ->whereIn('status', [ProcessingJob::STATUS_QUEUED, ProcessingJob::STATUS_RUNNING]))
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck clip renders found.');

            return self::SUCCESS;
        }

        foreach ($stuck as $clip) {
            // Reset progress rather than leaving it at whatever the lost attempt's
            // predecessor reached — RenderClipJob starts every fresh attempt at 5%
            // itself, so an old 55% left on screen would otherwise jump backwards
            // the moment the new attempt's first update lands.
            //
            // touch() is deliberate and load-bearing, not decorative: a clip that
            // was ALREADY status=queued/progress=0 (the common case — it never got
            // past that before its render job was lost) makes the update() above a
            // no-op, since nothing is actually dirty. Eloquent then never touches
            // updated_at, so the clip would still read as "stale" on the very next
            // run — before the redispatched job even reaches the front of the
            // queue — and get dispatched AGAIN, and again every run after that,
            // piling up duplicate RenderClipJob attempts for the same clip for as
            // long as the queue is backlogged.
            $clip->update(['status' => Clip::STATUS_QUEUED, 'progress' => 0]);
            $clip->touch();
            RenderClipJob::dispatch($clip->id);

            Log::warning('Reaped a clip whose render job was lost before running', [
                'clip_id' => $clip->id,
                'project_id' => $clip->project_id,
                'stale_for_minutes' => $minutes,
            ]);

            $this->warn("Re-dispatched render for clip #{$clip->id} (stuck for over {$minutes} minutes).");
        }

        return self::SUCCESS;
    }
}
