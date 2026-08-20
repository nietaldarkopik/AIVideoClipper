<?php

namespace App\Services\Video;

use App\Jobs\ImportVideoJob;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoBatchItem;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Downloads exactly one VideoBatchItem's source video, if it isn't already
 * imported. Split out of ProcessBatchItemJob so the batch orchestrator
 * (ProcessVideoBatchJob) can run just this step, synchronously and strictly one
 * item at a time, then hand the rest of the pipeline (analyze/render/publish) off
 * to a separately-dispatched ProcessBatchItemJob — letting that heavier, slower
 * work overlap with downloading the *next* item instead of blocking it.
 *
 * "Already imported" is decided via ProjectReprocessor (same source of truth
 * ProcessBatchItemJob itself uses to resume), not the VideoBatchItem's own status
 * column — so calling this again for an item that's already past the import stage
 * is always a safe no-op.
 */
class BatchItemDownloader
{
    public function __construct(
        private readonly UrlVideoDownloader $downloader,
        private readonly ProjectReprocessor $reprocessor,
    ) {
    }

    /**
     * @return array{0: Project, 1: ?Video}
     */
    public function ensureImported(VideoBatchItem $item, User $user): array
    {
        [$project, $video] = $this->resolveProjectAndVideo($item, $user);

        $plan = $this->reprocessor->plan($project);
        if ($plan['stage'] !== ProjectReprocessor::STAGE_IMPORT) {
            return [$project, $video];
        }

        $item->update(['message' => 'Downloading video...']);

        if (! $video) {
            $video = $project->videos()->create([
                'source_type' => $this->downloader->detectSourceType($item->source_url),
                'source_url' => $item->source_url,
                'status' => 'pending',
            ]);
        }

        app()->call([new ImportVideoJob($video->id), 'handle']);
        $video = $video->fresh();
        if ($video->status !== 'ready') {
            throw new RuntimeException($video->failure_reason ?? 'Import failed.');
        }

        return [$project, $video];
    }

    /**
     * @return array{0: Project, 1: ?Video}
     */
    private function resolveProjectAndVideo(VideoBatchItem $item, User $user): array
    {
        if ($item->project_id) {
            $project = Project::find($item->project_id);
            if ($project) {
                return [$project, $project->videos()->latest()->first()];
            }
        }

        $project = $user->projects()->create([
            'title' => Str::limit($item->source_url, 250, ''),
            'status' => Project::STATUS_UPLOADING,
            'last_edited_at' => now(),
        ]);
        $item->update(['project_id' => $project->id]);

        return [$project, null];
    }
}
