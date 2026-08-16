<?php

namespace App\Console\Commands;

use App\Models\Clip;
use App\Models\ProcessingJob;
use App\Models\Project;
use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Finds ProcessingJob rows stuck at status=running with no progress update in a
 * while and marks them failed. Exists because nothing else notices when the PHP
 * process actually running a job dies uncleanly (PC restart, `queue:work` window
 * closed, OOM kill) — Windows PHP has no pcntl, so Laravel's own $timeout on the job
 * class is never enforced, and the row just sits at "running"/whatever progress it
 * last reached forever. Without this, the project/video/clip stays stuck in a
 * processing state and the frontend polls it indefinitely with nothing to show.
 *
 * Scheduled every 5 minutes from bootstrap/app.php; requires `php artisan
 * schedule:work` to actually be running (start-all.ps1/.bat start it as its own
 * window alongside redis/queue worker/etc).
 */
class ReapStalledProcessingJobs extends Command
{
    protected $signature = 'jobs:reap-stalled {--minutes= : Override the stall threshold from config}';

    protected $description = 'Mark ProcessingJob rows stuck at "running" with no recent progress as failed';

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes') ?? config('services.processing.stall_minutes', 20));

        $stale = ProcessingJob::where('status', ProcessingJob::STATUS_RUNNING)
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stalled jobs found.');

            return self::SUCCESS;
        }

        foreach ($stale as $job) {
            $reason = "Stalled — no progress for over {$minutes} minutes (the worker likely died, e.g. a PC restart). Try again.";

            $job->markFailed($reason);

            match ($job->type) {
                'render_clip' => $job->clip_id && Clip::where('id', $job->clip_id)
                    ->update(['status' => Clip::STATUS_FAILED, 'failure_reason' => $reason]),
                'import_video' => $job->video_id && Video::where('id', $job->video_id)
                    ->update(['status' => 'failed', 'failure_reason' => $reason]),
                default => null,
            };

            Project::where('id', $job->project_id)
                ->whereNotIn('status', [Project::STATUS_COMPLETED, Project::STATUS_FAILED])
                ->update(['status' => Project::STATUS_FAILED, 'failure_reason' => $reason]);

            Log::warning('Reaped a stalled ProcessingJob', [
                'id' => $job->id,
                'project_id' => $job->project_id,
                'type' => $job->type,
                'progress' => $job->progress,
                'message' => $job->message,
            ]);

            $this->warn("Reaped job #{$job->id} (project {$job->project_id}, {$job->type}, stuck at {$job->progress}%)");
        }

        return self::SUCCESS;
    }
}
