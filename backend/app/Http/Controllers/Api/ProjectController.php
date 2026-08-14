<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClipResource;
use App\Http\Resources\ProjectResource;
use App\Jobs\AnalyzeVideoJob;
use App\Jobs\RenderClipJob;
use App\Models\Clip;
use App\Models\ClipCandidate;
use App\Models\Project;
use App\Models\Template;
use Illuminate\Http\Request;
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
     * Turn selected AI clip candidates into rendered Clip records + render jobs.
     * Body: { candidate_ids?: int[], mode?: top_3|top_5|top_10|all, template_id, aspect_ratio?, subtitle_language? }
     */
    public function generateClips(Request $request, Project $project)
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
            return response()->json(['message' => 'No clip candidates matched.'], 422);
        }

        $template = null;
        if (! empty($data['template_id'])) {
            $template = Template::with('currentVersion')->find($data['template_id']);
        }

        $clips = [];
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
                'status' => Clip::STATUS_QUEUED,
            ]);

            $candidate->update(['status' => 'generated']);

            RenderClipJob::dispatch($clip->id);
            $clips[] = $clip;
        }

        $project->update(['status' => Project::STATUS_RENDERING, 'last_edited_at' => now()]);

        return ClipResource::collection(collect($clips))->response()->setStatusCode(201);
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        if ($project->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }
    }
}
