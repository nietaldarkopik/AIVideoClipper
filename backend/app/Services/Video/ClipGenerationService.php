<?php

namespace App\Services\Video;

use App\Jobs\GenerateClipEmbeddingJob;
use App\Models\Clip;
use App\Models\ClipCandidate;
use App\Models\Project;
use App\Models\Template;
use App\Services\AI\Contracts\ReactionScriptProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns selected AI clip candidates into Clip rows, ready to be rendered.
 * Shared by the manual "Generate Clips" flow (ProjectController::generateClips,
 * which dispatches RenderClipJob per clip afterward) and the batch autobot
 * (ProcessVideoBatchJob, which renders each clip in-process instead).
 */
class ClipGenerationService
{
    public function __construct(
        private readonly ReactionScriptProvider $reactionScripts,
    ) {}

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

        $candidates = $query->with('video')->get();

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
                'caption' => ClipCreditFormatter::append($candidate->suggested_caption, $candidate->video?->channelName()),
                'hashtags' => $candidate->suggested_hashtags,
                // The first of the AI's thumbnail-length variants (see
                // ClipCandidateData::coverPromptInstructions()) becomes this
                // clip's cover text, so a generated cover is already
                // eye-catching without anyone opening the Cover tab — the other
                // variants stay on the candidate for one-click swapping there.
                // Falls back to the caption-length title only if the analysis
                // predates this feature.
                'cover_text' => $candidate->cover_titles[0] ?? $candidate->suggested_title,
                'cover_kicker' => $candidate->cover_subtitles[0] ?? null,
                'cover_subline' => $candidate->cover_subtitles[1] ?? null,
                'start_time' => $candidate->start_time,
                'end_time' => $candidate->end_time,
                'duration' => $candidate->duration,
                // The template is authoritative once one is selected — its own
                // aspect_ratio/resolution is what actually drives render
                // dimensions (see Clip::targetResolution() and RenderClipJob),
                // so a clip must never end up with an aspect_ratio that
                // disagrees with its template (that mismatch is what caused
                // the crop-then-stretch distortion this field ordering fixes).
                // The request's aspect_ratio is only ever a template FILTER on
                // the frontend, never an independent render parameter.
                'aspect_ratio' => $template?->aspect_ratio ?? $data['aspect_ratio'] ?? '9:16',
                'subtitle_language' => $data['subtitle_language'] ?? 'en',
                'subtitles_enabled' => $data['subtitles_enabled'] ?? true,
                'webcam_path' => $data['webcam_path'] ?? null,
                'reaction_layout' => $data['reaction_layout'] ?? null,
                'status' => Clip::STATUS_QUEUED,
            ]);

            $candidate->update(['status' => 'generated']);

            $this->attachReactionScript($clip);
            GenerateClipEmbeddingJob::dispatch($clip->id);

            $clips->push($clip);
        }

        return $clips;
    }

    /**
     * Auto-fills the AI reaction intro (see ReactionScriptProvider/RenderClipJob)
     * right when a clip is created, so the clip editor never needs a manual
     * "Generate" click for the common case — only TTS narration stays lazy
     * (synthesized on first render, see RenderClipJob::composeIntroOutro()) since
     * that costs a real API call per clip and shouldn't block clip creation for a
     * whole batch. Never fails clip creation itself: a transient AI error here
     * just leaves reaction_script empty, same as any pre-this-feature clip — the
     * "Generate Reaction Intro" button in the editor still covers that case.
     */
    private function attachReactionScript(Clip $clip): void
    {
        try {
            $result = $this->reactionScripts->generateReactionScript($clip->load(['clipCandidate', 'video.transcript']));

            $clip->update([
                'reaction_script' => $result->text,
                'reaction_tone' => $result->tone,
                'intro_enabled' => true,
                'outro_enabled' => true,
            ]);
        } catch (Throwable $e) {
            Log::warning('Reaction script auto-generation failed, leaving clip without one', [
                'clip_id' => $clip->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
