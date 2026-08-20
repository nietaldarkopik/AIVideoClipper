<?php

namespace App\Jobs;

use App\Models\VideoBatch;
use App\Models\VideoBatchItem;
use App\Services\Video\BatchItemDownloader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * The "batch autobot" downloader: walks every VideoBatchItem in a batch and
 * downloads each one's source video, strictly one at a time and never
 * concurrently — yt-dlp hitting the same platform back-to-back-to-back from
 * several downloads at once reads as bot-like and risks a 403 far more than one
 * download at a time does.
 *
 * Downloading is deliberately the ONLY thing this job does itself. As soon as an
 * item finishes downloading, the rest of its pipeline (transcribe/analyze/render/
 * publish — the slow, CPU-heavy part) is handed off to a separately-dispatched
 * ProcessBatchItemJob on the normal 'default' queue, and this loop moves straight
 * on to downloading the *next* item instead of waiting for that to finish. That
 * overlap (download item N+1 while item N is still being processed) is the reason
 * this job runs on its own dedicated 'batch-downloads' queue/worker — see
 * start-all.ps1/.bat — separate from the 'default' worker that both regular
 * (non-batch) projects and every batch item's processing stage share, which keeps
 * "only one ffmpeg/whisper/ollama pipeline running at a time" true for the heavy
 * work even though downloading and processing can now overlap.
 */
class ProcessVideoBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public int $videoBatchId)
    {
    }

    /**
     * A second batch dispatched while one is already downloading waits its turn —
     * two batches' downloads must not interleave any more than items within one
     * batch may. Processing (ProcessBatchItemJob) uses its own per-item lock
     * instead, since it's meant to run concurrently with this loop.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('video-batch-worker'))->releaseAfter(30)->expireAfter(6 * 3600)];
    }

    public function handle(BatchItemDownloader $batchDownloader): void
    {
        $batch = VideoBatch::findOrFail($this->videoBatchId);
        $batch->update(['status' => VideoBatch::STATUS_RUNNING, 'started_at' => $batch->started_at ?? now()]);

        $delaySeconds = max(0, (int) config('services.video_batch.download_delay_seconds', 10));
        $items = $batch->items()->orderBy('position')->get();

        foreach ($items as $index => $item) {
            if (in_array($item->status, VideoBatchItem::TERMINAL_STATUSES, true)) {
                continue;
            }

            if (VideoBatch::whereKey($batch->id)->value('cancel_requested')) {
                $batch->items()->where('status', VideoBatchItem::STATUS_PENDING)
                    ->update(['status' => VideoBatchItem::STATUS_CANCELLED, 'finished_at' => now()]);
                break;
            }

            $item->update(['status' => VideoBatchItem::STATUS_IMPORTING, 'progress' => 0, 'message' => 'Starting...', 'started_at' => $item->started_at ?? now()]);

            try {
                $batchDownloader->ensureImported($item, $batch->user);
                ProcessBatchItemJob::dispatch($item->id);
            } catch (Throwable $e) {
                $item->update([
                    'status' => VideoBatchItem::STATUS_FAILED,
                    'failure_reason' => $e->getMessage(),
                    'finished_at' => now(),
                ]);
                $batch->settleStatusIfAllItemsTerminal();
            }

            // Space downloads out even when yt-dlp itself returns quickly — the whole
            // point is not to hit the source platform back-to-back. Skip the wait
            // after the last item, no reason to delay the job from finishing.
            if ($delaySeconds > 0 && $index < $items->count() - 1) {
                sleep($delaySeconds);
            }
        }

        // Every item that made it past downloading has its processing dispatched to
        // 'default' and will settle the batch's final status itself once done (see
        // VideoBatch::settleStatusIfAllItemsTerminal, called from ProcessBatchItemJob).
        // This covers only the edge case where every item was already terminal on
        // entry (e.g. re-dispatching an already-finished batch) — nothing left to
        // dispatch, so nothing else would ever call it for this run.
        $batch->settleStatusIfAllItemsTerminal();
    }
}
