<?php

namespace App\Services\Video;

use App\Models\Clip;
use App\Models\ClipCandidate;
use App\Models\Project;
use App\Models\Video;

/**
 * Figures out which pipeline stage a project needs to resume from — used both by
 * the manual "Reprocess" button on a failed project (ProjectController::reprocess,
 * which only resumes up through rendering — publishing stays a deliberate manual
 * step there) and by the batch autobot's per-item retry (ProcessBatchItemJob, which
 * resumes the same way but continues on through auto-publish since that's the
 * whole point of a batch item). Kept as pure decision logic (no dispatching) so
 * both callers can decide what to do with the plan.
 */
class ProjectReprocessor
{
    public const STAGE_IMPORT = 'import';
    public const STAGE_ANALYZE = 'analyze';
    public const STAGE_RENDER = 'render';
    public const STAGE_SELECT_AND_RENDER = 'select_and_render';
    public const STAGE_NONE = 'none';

    /**
     * @return array{stage: string, video: ?Video, clips: \Illuminate\Support\Collection<int, Clip>}
     */
    public function plan(Project $project): array
    {
        $video = $project->videos()->latest()->first();

        if (! $video || $video->status !== 'ready') {
            return ['stage' => self::STAGE_IMPORT, 'video' => $video, 'clips' => collect()];
        }

        if (ClipCandidate::where('video_id', $video->id)->count() === 0) {
            return ['stage' => self::STAGE_ANALYZE, 'video' => $video, 'clips' => collect()];
        }

        $failedClips = Clip::where('project_id', $project->id)->where('status', Clip::STATUS_FAILED)->get();
        if ($failedClips->isNotEmpty()) {
            return ['stage' => self::STAGE_RENDER, 'video' => $video, 'clips' => $failedClips];
        }

        if (! Clip::where('project_id', $project->id)->exists()) {
            return ['stage' => self::STAGE_SELECT_AND_RENDER, 'video' => $video, 'clips' => collect()];
        }

        return ['stage' => self::STAGE_NONE, 'video' => $video, 'clips' => collect()];
    }
}
