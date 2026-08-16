<?php

namespace App\Jobs\Concerns;

use App\Exceptions\JobCancelledException;
use App\Models\ProcessingJob;

/**
 * Cooperative cancellation for long-running jobs. Windows PHP has no pcntl, so
 * nothing can forcibly interrupt a job mid-HTTP-call to whisper-engine/Ollama/ffmpeg
 * the way a signal would on Linux — the best available mechanism is for the job to
 * check in between its own phases (the same points already marked by
 * `$processingJob->markProgress(...)`) and bail out on its own if someone flagged it
 * cancelled in the meantime.
 */
trait ChecksCancellation
{
    /**
     * Re-fetch just the status column (not the whole row) and abort if it's been
     * marked cancelled since this job started. Call this right before each
     * blocking external call, not inside a tight loop.
     */
    protected function abortIfCancelled(ProcessingJob $processingJob): void
    {
        if ($this->isCancelled($processingJob)) {
            throw new JobCancelledException('Cancelled by user.');
        }
    }

    /**
     * Non-throwing version, for passing as a callback into a provider whose own
     * internal loop (whisper-engine's per-audio-chunk calls, Ollama's per-transcript-
     * window calls) is the actual multi-minute hang point — checking only between a
     * job's own high-level phases would mean "Stop" doesn't take effect until the
     * *entire* transcribe()/analyzeMoments() call returns, which defeats the point.
     */
    protected function isCancelled(ProcessingJob $processingJob): bool
    {
        return ProcessingJob::whereKey($processingJob->id)->value('status') === ProcessingJob::STATUS_CANCELLED;
    }
}
