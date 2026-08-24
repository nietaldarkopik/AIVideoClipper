<?php

namespace App\Services\Video;

use App\Models\Clip;
use App\Models\ClipCandidate;
use App\Models\Project;
use App\Models\Template;
use Illuminate\Support\Collection;

/**
 * Turns selected AI clip candidates into Clip rows, ready to be rendered.
 * Shared by the manual "Generate Clips" flow (ProjectController::generateClips,
 * which dispatches RenderClipJob per clip afterward) and the batch autobot
 * (ProcessVideoBatchJob, which renders each clip in-process instead).
 */
class ClipGenerationService
{
    /**
     * @param  array{candidate_ids?: array<int, int>, mode?: string, template_id?: ?int, aspect_ratio?: string, subtitle_language?: string, subtitles_enabled?: bool, webcam_path?: ?string, reaction_layout?: ?string}  $data
     * @return Collection<int, Clip>
     */
    public function selectAndCreateClips(Project $project, array $data): Collection
    {
        $query = ClipCandidate::where('project_id', $project->id);

        if (! empty($data['candidate_ids'])) {
            $query->whereIn('id', $data['candidate_ids']);
        } else {
            $query->orderByDesc('overall_score');
            $limit = match ($data['mode'] ?? 'top_5') {
                'top_3' => 3,
                'top_10' => 10,
                'all' => null,
                default => 5,
            };
            if ($limit) {
                $query->limit($limit);
            }
        }

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            return collect();
        }

        $template = null;
        if (! empty($data['template_id'])) {
            $template = Template::with('currentVersion')->find($data['template_id']);
        }

        $clips = collect();
        foreach ($candidates as $candidate) {
            $clip = Clip::create([
                'project_id' => $project->id,
                'video_id' => $candidate->video_id,
                'clip_candidate_id' => $candidate->id,
                'template_id' => $template?->id,
                'template_version_id' => $template?->current_version_id,
                'title' => $candidate->suggested_title,
                'caption' => $candidate->suggested_caption,
                'hashtags' => $candidate->suggested_hashtags,
                'start_time' => $candidate->start_time,
                'end_time' => $candidate->end_time,
                'duration' => $candidate->duration,
                'aspect_ratio' => $data['aspect_ratio'] ?? $template?->aspect_ratio ?? '9:16',
                'subtitle_language' => $data['subtitle_language'] ?? 'en',
                'subtitles_enabled' => $data['subtitles_enabled'] ?? true,
                'webcam_path' => $data['webcam_path'] ?? null,
                'reaction_layout' => $data['reaction_layout'] ?? null,
                'status' => Clip::STATUS_QUEUED,
            ]);

            $candidate->update(['status' => 'generated']);

            $clips->push($clip);
        }

        return $clips;
    }
}
