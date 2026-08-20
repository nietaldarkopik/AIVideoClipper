<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClipResource;
use App\Http\Resources\ProjectResource;
use App\Jobs\AnalyzeVideoJob;
use App\Jobs\ImportVideoJob;
use App\Jobs\RenderClipJob;
use App\Models\Clip;
use App\Models\Project;
use App\Services\Video\ClipGenerationService;
use App\Services\Video\ProjectReprocessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $projects = $request->user()->projects()
            ->withCount(['clips', 'clipCandidates'])
            ->with(['videos' => fn ($q) => $q->latest()->limit(1)])
            ->orderByDesc('last_edited_at')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 12));

        return ProjectResource::collection($projects);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $project = $request->user()->projects()->create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => Project::STATUS_DRAFT,
            'last_edited_at' => now(),
        ]);

        return ProjectResource::make($project)->response()->setStatusCode(201);
    }

    public function show(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $project->load(['videos.transcript']);
        $project->loadCount(['clips', 'clipCandidates']);

        return ProjectResource::make($project);
    }

    public function update(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $project->update([...$data, 'last_edited_at' => now()]);

        return ProjectResource::make($project);
    }

    public function destroy(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $project->delete();

        return response()->json(['message' => 'Project deleted.']);
    }

    public function analyze(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $video = $project->videos()->latest()->first();

        if (! $video || $video->status !== 'ready') {
            return response()->json(['message' => 'Video is not ready to analyze yet.'], 422);
        }

        AnalyzeVideoJob::dispatch($project->id, $video->id);

        $project->update(['status' => Project::STATUS_PROCESSING, 'last_edited_at' => now()]);

        return ProjectResource::make($project);
    }

    /**
     * Retry a failed project from wherever it actually broke — re-download if the
     * import failed, re-analyze if the transcript/candidates never came back,
     * re-render any clip that failed. Never auto-publishes: that stays a deliberate
     * manual step from the Publish panel, same as a normal (non-batch) project.
     */
    public function reprocess(Request $request, Project $project, ProjectReprocessor $reprocessor)
    {
        $this->authorizeProject($request, $project);

        if ($project->status !== Project::STATUS_FAILED) {
            return response()->json(['message' => 'Only a failed project can be reprocessed.'], 422);
        }

        $plan = $reprocessor->plan($project);

        switch ($plan['stage']) {
            case ProjectReprocessor::STAGE_IMPORT:
                $video = $plan['video'];
                if (! $video) {
                    return response()->json(['message' => 'This project has no video to re-import.'], 422);
                }
                $video->update(['status' => 'pending', 'failure_reason' => null]);
                $project->update(['status' => Project::STATUS_UPLOADING, 'failure_reason' => null, 'last_edited_at' => now()]);
                // Chained: AnalyzeVideoJob only runs if the re-import actually succeeds.
                Bus::chain([new ImportVideoJob($video->id), new AnalyzeVideoJob($project->id, $video->id)])->dispatch();
                $message = 'Re-downloading the video, then re-analyzing.';
                break;

            case ProjectReprocessor::STAGE_ANALYZE:
                $project->update(['status' => Project::STATUS_PROCESSING, 'failure_reason' => null, 'last_edited_at' => now()]);
                AnalyzeVideoJob::dispatch($project->id, $plan['video']->id);
                $message = 'Re-analyzing the video.';
                break;

            case ProjectReprocessor::STAGE_RENDER:
                foreach ($plan['clips'] as $clip) {
                    $clip->update(['status' => Clip::STATUS_QUEUED, 'progress' => 0, 'failure_reason' => null]);
                    RenderClipJob::dispatch($clip->id);
                }
                $project->update(['status' => Project::STATUS_RENDERING, 'failure_reason' => null, 'last_edited_at' => now()]);
                $message = sprintf('Re-rendering %d failed clip(s).', $plan['clips']->count());
                break;

            default:
                // Nothing actually looks broken (e.g. candidates exist but rendering
                // was never started) — just clear the failed flag; the normal
                // "Generate Clips" flow picks up from here.
                $project->update(['status' => Project::STATUS_COMPLETED, 'failure_reason' => null, 'last_edited_at' => now()]);
                $message = 'Nothing to re-run — pick clips to generate below.';
        }

        return ProjectResource::make($project->fresh())->additional(['message' => $message]);
    }

    /**
     * Turn selected AI clip candidates into rendered Clip records + render jobs.
     * Body: { candidate_ids?: int[], mode?: top_3|top_5|top_10|all, template_id, aspect_ratio?, subtitle_language? }
     */
    public function generateClips(Request $request, Project $project, ClipGenerationService $clipGeneration)
    {
        $this->authorizeProject($request, $project);

        $data = $request->validate([
            'candidate_ids' => ['sometimes', 'array'],
            'candidate_ids.*' => ['integer'],
            'mode' => ['sometimes', Rule::in(['top_3', 'top_5', 'top_10', 'all'])],
            'template_id' => ['nullable', 'exists:templates,id'],
            'aspect_ratio' => ['sometimes', Rule::in(['9:16', '1:1', '16:9'])],
            'subtitle_language' => ['sometimes', 'string', 'max:10'],
            'subtitles_enabled' => ['sometimes', 'boolean'],
        ]);

        $clips = $clipGeneration->selectAndCreateClips($project, $data);

        if ($clips->isEmpty()) {
            return response()->json(['message' => 'No clip candidates matched.'], 422);
        }

        foreach ($clips as $clip) {
            RenderClipJob::dispatch($clip->id);
        }

        $project->update(['status' => Project::STATUS_RENDERING, 'last_edited_at' => now()]);

        return ClipResource::collection($clips)->response()->setStatusCode(201);
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        if ($project->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }
    }
}
