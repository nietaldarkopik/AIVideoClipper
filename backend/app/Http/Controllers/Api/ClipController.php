<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClipResource;
use App\Jobs\RenderClipJob;
use App\Models\Clip;
use App\Services\AI\Contracts\SocialMetadataProvider;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use ZipArchive;

class ClipController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->user()->isAdmin()
            ? Clip::query()
            : Clip::whereHas('project', fn ($q) => $q->where('user_id', $request->user()->id));

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $clips = $query->with(['template', 'subtitle'])
            ->latest()
            ->paginate($request->integer('per_page', 24));

        return ClipResource::collection($clips);
    }

    public function show(Request $request, Clip $clip)
    {
        $this->authorizeClip($request, $clip);

        return ClipResource::make($clip->load(['template.currentVersion', 'subtitle', 'clipCandidate']));
    }

    /**
     * Manual editing: trim, aspect ratio / crop, template swap, caption changes.
     * Any change that affects the rendered output re-queues a render.
     */
    public function update(Request $request, Clip $clip)
    {
        $this->authorizeClip($request, $clip);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'caption' => ['sometimes', 'nullable', 'string'],
            'hashtags' => ['sometimes', 'array'],
            'start_time' => ['sometimes', 'numeric', 'min:0'],
            'end_time' => ['sometimes', 'numeric', 'min:0'],
            'aspect_ratio' => ['sometimes', Rule::in(['9:16', '1:1', '16:9'])],
            'template_id' => ['sometimes', 'nullable', 'exists:templates,id'],
            'subtitles_enabled' => ['sometimes', 'boolean'],
            'subtitle_language' => ['sometimes', 'string', 'max:10'],
            'subtitle_config' => ['sometimes', 'array'],
            'scenes' => ['sometimes', 'array'],
        ]);

        $reRenderFields = ['start_time', 'end_time', 'aspect_ratio', 'template_id', 'subtitles_enabled', 'subtitle_language', 'subtitle_config', 'scenes'];
        $needsRerender = ! empty(array_intersect(array_keys($data), $reRenderFields));

        if (array_key_exists('template_id', $data)) {
            $template = $data['template_id'] ? \App\Models\Template::find($data['template_id']) : null;
            $data['template_version_id'] = $template?->current_version_id;
        }

        if (isset($data['start_time']) || isset($data['end_time'])) {
            $start = $data['start_time'] ?? $clip->start_time;
            $end = $data['end_time'] ?? $clip->end_time;
            $data['duration'] = max(0.1, $end - $start);
        }

        $clip->update($data);

        if ($needsRerender) {
            $clip->update(['status' => Clip::STATUS_QUEUED, 'progress' => 0]);
            RenderClipJob::dispatch($clip->id);
        }

        return ClipResource::make($clip->fresh(['template']));
    }

    public function destroy(Request $request, Clip $clip)
    {
        $this->authorizeClip($request, $clip);
        $clip->delete();

        return response()->json(['message' => 'Clip deleted.']);
    }

    /**
     * AI-generated per-platform title/caption/description/hashtags/CTA suggestions.
     */
    public function generateSocialMetadata(Request $request, Clip $clip, SocialMetadataProvider $provider)
    {
        $this->authorizeClip($request, $clip);

        $data = $request->validate([
            'platforms' => ['sometimes', 'array'],
            'platforms.*' => ['string', Rule::in(['tiktok', 'youtube', 'instagram', 'facebook', 'twitter', 'linkedin'])],
        ]);

        $platforms = $data['platforms'] ?? ['tiktok', 'instagram', 'youtube', 'facebook', 'twitter', 'linkedin'];

        return response()->json(['metadata' => $provider->generateMetadata($clip->load('clipCandidate'), $platforms)]);
    }

    public function duplicate(Request $request, Clip $clip)
    {
        $this->authorizeClip($request, $clip);

        $copy = $clip->replicate(['output_path', 'thumbnail_path', 'rendered_at', 'status', 'progress']);
        $copy->status = Clip::STATUS_QUEUED;
        $copy->progress = 0;
        $copy->output_path = null;
        $copy->thumbnail_path = null;
        $copy->rendered_at = null;
        $copy->save();

        RenderClipJob::dispatch($copy->id);

        return ClipResource::make($copy)->response()->setStatusCode(201);
    }

    public function regenerate(Request $request, Clip $clip)
    {
        $this->authorizeClip($request, $clip);

        $clip->update(['status' => Clip::STATUS_QUEUED, 'progress' => 0, 'failure_reason' => null]);
        RenderClipJob::dispatch($clip->id);

        return ClipResource::make($clip);
    }

    /**
     * Bundle several completed clips into a ZIP for download.
     */
    public function exportZip(Request $request)
    {
        $data = $request->validate([
            'clip_ids' => ['required', 'array', 'min:1'],
            'clip_ids.*' => ['integer'],
        ]);

        $clips = Clip::whereIn('id', $data['clip_ids'])
            ->where('status', Clip::STATUS_COMPLETED)
            ->get()
            ->filter(fn (Clip $clip) => $clip->project->user_id === $request->user()->id || $request->user()->isAdmin());

        if ($clips->isEmpty()) {
            return response()->json(['message' => 'No completed clips matched.'], 422);
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('media');
        $zipRelative = 'exports/' . $request->user()->id . '_' . now()->timestamp . '.zip';
        $zipFullPath = $disk->path($zipRelative);
        @mkdir(dirname($zipFullPath), 0775, true);

        $zip = new ZipArchive();
        $zip->open($zipFullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($clips as $clip) {
            if ($clip->output_path && $disk->exists($clip->output_path)) {
                $name = ($clip->title ? \Illuminate\Support\Str::slug($clip->title) : 'clip-' . $clip->id) . '.mp4';
                $zip->addFile($disk->path($clip->output_path), $name);
            }
        }

        $zip->close();

        return response()->download($zipFullPath, 'clips-export.zip')->deleteFileAfterSend(true);
    }

    private function authorizeClip(Request $request, Clip $clip): void
    {
        if ($clip->project->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }
    }
}
