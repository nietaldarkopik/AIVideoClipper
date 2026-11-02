<?php

namespace App\Console\Commands;

use App\Models\VideoBatch;
use App\Models\VideoBatchItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Finds VideoBatch rows stuck at status=running whose worker is gone — nothing
 * else notices this. ProcessVideoBatchJob's own retryUntil() (7h) only saves a
 * batch that's still genuinely queued somewhere in Redis; it does nothing once
 * the job itself has been lost entirely (confirmed cause here: `php artisan
 * queue:restart` kills every long-running worker and nothing on this Windows
 * dev setup auto-restarts them — see start-all.bat/.ps1, plain `cmd /k` windows,
 * no supervisor — so a batch mid-download when that happens is left at
 * status=running forever with no job left anywhere to ever pick it back up).
 *
 * This is the VideoBatch equivalent of jobs:reap-stalled (ProcessingJob) — same
 * "reached running, then went quiet" detection, just for a model that has no
 * ProcessingJob row of its own. A batch's own updated_at only changes when it
 * starts or fully settles, not per item, so staleness is judged against BOTH the
 * batch row and its most recently touched item — whichever moved more recently.
 *
 * This is also what makes the single/bulk "Stop" button (VideoBatchController::
 * cancel/bulkCancel) actually take effect for a batch whose job is already gone:
 * that endpoint only sets cancel_requested=true and trusts a live job to notice
 * it between items — which never happens for a zombie batch. This reaper is what
 * turns that flag into an actual STATUS_CANCELLED once it's clear nothing is
 * left to honor it cooperatively.
 *
 * Scheduled every 5 minutes from bootstrap/app.php; requires `php artisan
 * schedule:work` to actually be running (start-all.ps1/.bat start it).
 */
class ReapStalledVideoBatches extends Command
{
    protected $signature = 'batches:reap-stalled {--minutes= : Override the stall threshold from config}';

    protected $description = 'Mark VideoBatch rows stuck at "running" with no recent activity as cancelled/failed';

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes') ?? config('services.processing.stall_minutes', 20));
        $cutoff = now()->subMinutes($minutes);

        $stale = VideoBatch::where('status', VideoBatch::STATUS_RUNNING)
            ->where('updated_at', '<', $cutoff)
            ->get()
            ->filter(function (VideoBatch $batch) use ($cutoff) {
                $lastItemActivity = $batch->items()->max('updated_at');

                return ! $lastItemActivity || $lastItemActivity < $cutoff;
            });

        if ($stale->isEmpty()) {
            $this->info('No stalled video batches found.');

            return self::SUCCESS;
        }

        foreach ($stale as $batch) {
            // A batch the user already asked to stop settles as cancelled — that
            // was the whole point of the click, this reaper just finishes it since
            // no live job remains to do so cooperatively. Anything else stuck here
            // genuinely lost its worker (crash, PC restart, an unsupervised
            // queue:restart) rather than being asked to stop, so it settles failed.
            $reason = $batch->cancel_requested
                ? 'Batch stopped.'
                : "Stalled — no progress for over {$minutes} minutes (the worker likely died, e.g. a PC restart). Try again.";

            $batch->items()->whereNotIn('status', VideoBatchItem::TERMINAL_STATUSES)->update([
                'status' => $batch->cancel_requested ? VideoBatchItem::STATUS_CANCELLED : VideoBatchItem::STATUS_FAILED,
                'failure_reason' => $batch->cancel_requested ? null : $reason,
                'finished_at' => now(),
            ]);

            $batch->update([
                'status' => $batch->cancel_requested ? VideoBatch::STATUS_CANCELLED : VideoBatch::STATUS_FAILED,
                'finished_at' => now(),
            ]);

            Log::warning('Reaped a stalled VideoBatch', [
                'id' => $batch->id,
                'cancel_requested' => $batch->cancel_requested,
                'stale_for_minutes' => $minutes,
            ]);

            $this->warn("Reaped batch #{$batch->id} (stuck at running for over {$minutes} minutes, cancel_requested=".($batch->cancel_requested ? 'true' : 'false').').');
        }

        return self::SUCCESS;
    }
}
