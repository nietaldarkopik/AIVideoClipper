<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CoverTemplateResource;
use App\Models\Clip;
use App\Models\CoverTemplate;
use App\Models\Video;
use App\Services\Video\CoverGeneratorService;
use App\Services\Video\DefaultCoverTemplateConfig;
use App\Services\Video\FFmpegService;
use App\Services\Video\TemplatePreviewService;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class CoverTemplateController extends Controller
{
    public function index(Request $request)
    {
        $query = CoverTemplate::where('status', '!=', 'archived');

        if ($request->boolean('include_archived') && $request->user()?->isAdmin()) {
            $query = CoverTemplate::query();
        }

        return CoverTemplateResource::collection($query->orderBy('name')->get());
    }

    public function show(CoverTemplate $coverTemplate)
    {
        return CoverTemplateResource::make($coverTemplate);
    }

    /**
     * A raw, text-free still from the shared demo video (the same source
     * TemplatePreviewService renders video-template previews from) that the
     * cover-template editor draws its live preview over. Cached on disk after
     * the first call — it never changes, so re-grabbing it per page load would
     * just be a wasted ffmpeg run.
     */
    public function demoFrame(FFmpegService $ffmpeg)
    {
        $disk = Storage::disk('media');
        $relative = 'covers/_demo/frame.jpg';

        if (! $disk->exists($relative)) {
            $video = Video::find(TemplatePreviewService::DEMO_VIDEO_ID);
            if (! $video || ! $disk->exists($video->disk_path)) {
                return response()->json(['url' => null]);
            }

            $ffmpeg->generateThumbnail($disk->path($video->disk_path), $disk->path($relative), 4.0);
        }

        return response()->json(['url' => Media::url($relative)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'aspect_ratio' => ['required', Rule::in(['9:16', '1:1', '16:9'])],
            'config' => ['nullable', 'array'],
        ]);

        $coverTemplate = CoverTemplate::create([
            'created_by' => $request->user()->id,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::random(4),
            'description' => $data['description'] ?? null,
            'aspect_ratio' => $data['aspect_ratio'],
            'config' => array_replace_recursive(DefaultCoverTemplateConfig::config(), $data['config'] ?? []),
            'status' => 'published',
        ]);

        return CoverTemplateResource::make($coverTemplate)->response()->setStatusCode(201);
    }

    public function update(Request $request, CoverTemplate $coverTemplate)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'aspect_ratio' => ['sometimes', Rule::in(['9:16', '1:1', '16:9'])],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'config' => ['sometimes', 'array'],
        ]);

        if (array_key_exists('config', $data)) {
            $data['config'] = array_replace_recursive($coverTemplate->config ?? DefaultCoverTemplateConfig::config(), $data['config']);
        }

        $coverTemplate->update($data);

        return CoverTemplateResource::make($coverTemplate->fresh());
    }

    public function duplicate(Request $request, CoverTemplate $coverTemplate)
    {
        $copy = $coverTemplate->replicate(['slug']);
        $copy->name = $coverTemplate->name.' (Copy)';
        $copy->slug = Str::slug($copy->name).'-'.Str::random(4);
        $copy->status = 'draft';
        $copy->is_system = false;
        $copy->created_by = $request->user()->id;
        $copy->save();

        return CoverTemplateResource::make($copy)->response()->setStatusCode(201);
    }

    /**
     * Admin only: render a sample cover for this template's picker card, from
     * whichever completed clip was most recently rendered (same "one real
     * shared sample" idea as TemplatePreviewService, just picking a real clip
     * instead of a fixed demo video since a cover needs an already-rendered
     * output.mp4 to grab a frame from).
     */
    public function generateThumbnail(CoverTemplate $coverTemplate, CoverGeneratorService $covers)
    {
        $sampleClip = Clip::where('status', Clip::STATUS_COMPLETED)
            ->whereNotNull('output_path')
            ->orderByDesc('id')
            ->first();

        if (! $sampleClip) {
            return response()->json(['message' => 'No completed clip available yet to sample a cover from.'], 422);
        }

        try {
            $relative = $covers->generate($sampleClip, $coverTemplate, 'This Is Your Headline');
            $coverTemplate->update(['thumbnail_path' => $relative]);
        } catch (Throwable $e) {
            Log::warning('Cover template thumbnail generation failed', [
                'cover_template_id' => $coverTemplate->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Cover generation failed: '.$e->getMessage()], 422);
        }

        return CoverTemplateResource::make($coverTemplate->fresh());
    }

    public function archive(CoverTemplate $coverTemplate)
    {
        $coverTemplate->update(['status' => 'archived']);

        return CoverTemplateResource::make($coverTemplate);
    }

    public function destroy(CoverTemplate $coverTemplate)
    {
        $coverTemplate->delete();

        return response()->json(['message' => 'Cover template deleted.']);
    }
}
