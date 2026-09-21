<?php

namespace App\Jobs;

use App\Models\ResearchRun;
use App\Services\Research\ChannelResearchEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the research pipeline for one channel.
 *
 * One job per channel, dispatched separately by DailyContentResearchCommand, is
 * what makes "one channel failing must not stop the others" true at the
 * infrastructure level rather than only inside a try/catch.
 *
 * tries = 1: a research run is not idempotent — a retry would re-query every
 * provider (burning rate limit) and could persist a second set of ideas for the
 * same run. The engine already handles per-provider failures internally, so a
 * retry would only help if the whole engine crashed, which is a bug to fix rather
 * than to paper over.
 */
class ResearchChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * A run queries a dozen network sources plus an LLM call carrying the full
     * research payload; the default 60s would kill it mid-pipeline.
     */
    public int $timeout = 900;

    public function __construct(public int $researchRunId) {}

    public function handle(ChannelResearchEngine $engine): void
    {
        $run = ResearchRun::find($this->researchRunId);

        if ($run === null) {
            // The channel (and its runs) was deleted between dispatch and execution.
            Log::info('research.job.run_missing', ['run_id' => $this->researchRunId]);

            return;
        }

        $engine->run($run);
    }

    public function failed(Throwable $e): void
    {
        // The engine marks the run failed itself for anything it catches; this only
        // covers the job dying outside it (timeout, OOM, a serialization error),
        // which would otherwise leave the run stuck on RUNNING forever.
        ResearchRun::where('id', $this->researchRunId)
            ->where('status', ResearchRun::STATUS_RUNNING)
            ->update([
                'status' => ResearchRun::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'message' => 'Job riset berhenti tidak wajar.',
                'finished_at' => now(),
            ]);

        Log::error('research.job.failed', ['run_id' => $this->researchRunId, 'error' => $e->getMessage()]);
    }
}
