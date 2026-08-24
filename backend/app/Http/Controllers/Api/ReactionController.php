<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClipResource;
use App\Jobs\RenderClipJob;
use App\Models\Clip;
use App\Models\ClipCandidate;
use App\Models\Project;
use App\Services\Video\ClipGenerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReactionController extends Controller
{
    private const LAYOUTS = ['pip_bottom_right', 'pip_bottom_left', 'split_top_bottom', 'split_side_by_side'];

    public function store(Request $request, ClipCandidate $clipCandidate, ClipGenerationService $clipGeneration)
    {
        $this->authorizeProject($request, $clipCandidate->project);

        $data = $request->validate([
            'webcam' => ['required', 'file', 'mimetypes:video/webm,video/mp4', 'max:512000'],
            'layout' => ['required', Rule::in(self::LAYOUTS)],
            'template_id' => ['nullable', 'exists:templates,id'],
            'aspect_ratio' => ['sometimes', Rule::in(['9:16', '1:1', '16:9'])],
        ]);

        $disk = Storage::disk('media');
        $webcamRelative = "reactions/{$clipCandidate->id}/source.webm";
        $disk->putFileAs("reactions/{$clipCandidate->id}", $data['webcam'], 'source.webm');

        $clips = $clipGeneration->selectAndCreateClips($clipCandidate->project, [
            'candidate_ids' => [$clipCandidate->id],
            'template_id' => $data['template_id'] ?? null,
            'aspect_ratio' => $data['aspect_ratio'] ?? '9:16',
            'webcam_path' => $webcamRelative,
            'reaction_layout' => $data['layout'],
        ]);

        if ($clips->isEmpty()) {
            return response()->json(['message' => 'Clip candidate not found or already generated.'], 422);
        }

        $clip = $clips->first();
        RenderClipJob::dispatch($clip->id);

        $clipCandidate->project->update(['status' => Project::STATUS_RENDERING, 'last_edited_at' => now()]);

        return ClipResource::make($clip)->response()->setStatusCode(201);
    }

    /**
     * Re-react to an already-generated clip: swap in a new webcam recording/layout
     * on the same Clip row and re-render in place — same idea as ClipController's
     * regenerate(), just also attaching a reaction first.
     */
    public function updateClip(Request $request, Clip $clip)
    {
        $this->authorizeClip($request, $clip);

        $data = $request->validate([
            'webcam' => ['required', 'file', 'mimetypes:video/webm,video/mp4', 'max:512000'],
            'layout' => ['required', Rule::in(self::LAYOUTS)],
        ]);

        $disk = Storage::disk('media');
        $webcamRelative = "reactions/clip-{$clip->id}/source.webm";
        $disk->putFileAs("reactions/clip-{$clip->id}", $data['webcam'], 'source.webm');

        $clip->update([
            'webcam_path' => $webcamRelative,
            'reaction_layout' => $data['layout'],
            'status' => Clip::STATUS_QUEUED,
            'progress' => 0,
            'failure_reason' => null,
        ]);

        RenderClipJob::dispatch($clip->id);

        return ClipResource::make($clip);
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        if ($project->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }
    }

    private function authorizeClip(Request $request, Clip $clip): void
    {
        if ($clip->project->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }
    }
}
