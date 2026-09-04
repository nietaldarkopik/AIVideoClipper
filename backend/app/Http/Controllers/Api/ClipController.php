<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClipResource;
use App\Jobs\GenerateClipEmbeddingJob;
use App\Jobs\RenderClipJob;
use App\Models\Clip;
use App\Services\AI\ClipSearchService;
use App\Services\AI\Contracts\ReactionScriptProvider;
use App\Services\AI\Contracts\SocialMetadataProvider;
use App\Services\AI\Contracts\TextToSpeechProvider;
use App\Services\AI\WebContentFetcher;
use App\Services\Video\ClipCreditFormatter;
use App\Services\Video\DefaultTemplateConfig;
use App\Services\Video\LayerOverrideMerger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    /**
     * Semantic search over the user's clips (title/caption/hashtags/hook/reaction
     * script), via ClipSearchService's brute-force cosine similarity — see its
     * docblock for scale caveats. Route must be registered before GET
     * /clips/{clip} or "search" gets swallowed by that route's {clip} binding.
     */
    public function search(Request $request, ClipSearchService $search)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:255'],
            'project_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $results = $search->search(
            userId: $request->user()->id,
            isAdmin: $request->user()->isAdmin(),
            query: $data['q'],
            projectId: $data['project_id'] ?? null,
        );

        return ClipResource::collection($results);
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
            // See FFmpegService::renderClip()'s setpts/atempo (speed) and volume=
            // (volume) blocks — 1.0 is a no-op for both, matching every clip
            // before this feature.
            'speed' => ['sometimes', 'numeric', 'min:0.5', 'max:2'],
            'volume' => ['sometimes', 'numeric', 'min:0', 'max:2'],
            'subtitles_enabled' => ['sometimes', 'boolean'],
            'subtitle_language' => ['sometimes', 'string', 'max:10'],
            'subtitle_config' => ['sometimes', 'array'],
            'scenes' => ['sometimes', 'array'],
            'layer_overrides' => ['sometimes', 'nullable', 'array'],
            'segments' => ['sometimes', 'nullable', 'array'],
            'segments.*.start' => ['required_with:segments', 'numeric', 'min:0'],
            'segments.*.end' => ['required_with:segments', 'numeric', 'min:0'],
            'crop_config' => ['sometimes', 'nullable', 'array'],
            'reaction_script' => ['sometimes', 'nullable', 'string'],
            'intro_enabled' => ['sometimes', 'boolean'],
            'outro_enabled' => ['sometimes', 'boolean'],
            'intro_voice' => ['sometimes', 'nullable', 'string', 'max:64'],
            'reference_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url'],
        ]);

        // A hand-edited script or a different voice invalidates whatever TTS audio
        // is already cached — clearing it here makes RenderClipJob regenerate it
        // lazily on the next render instead of narrating stale text. Compared
        // against the clip's current value (not just key-presence) because the
        // clip editor's "Save & Re-render" always resends reaction_script
        // unconditionally (same as title/caption/etc.) — keying off presence alone
        // would null out a perfectly good cached narration on every single save.
        if ((array_key_exists('reaction_script', $data) && $data['reaction_script'] !== $clip->reaction_script)
            || (array_key_exists('intro_voice', $data) && $data['intro_voice'] !== $clip->intro_voice)) {
            $data['intro_audio_path'] = null;
        }

        // The AI cover image is generated from reaction_script content (see
        // RenderClipJob::buildCoverPrompt()) — voice doesn't affect it, so only
        // clear the cache on an actual script change, not a voice change.
        if (array_key_exists('reaction_script', $data) && $data['reaction_script'] !== $clip->reaction_script) {
            $data['intro_cover_path'] = null;
        }

        $reRenderFields = ['start_time', 'end_time', 'aspect_ratio', 'template_id', 'subtitles_enabled', 'subtitle_language', 'subtitle_config', 'scenes', 'layer_overrides', 'segments', 'crop_config', 'reaction_script', 'intro_enabled', 'outro_enabled', 'intro_voice', 'speed', 'volume'];
        $needsRerender = ! empty(array_intersect(array_keys($data), $reRenderFields));

        if (array_key_exists('template_id', $data)) {
            $template = $data['template_id'] ? \App\Models\Template::find($data['template_id']) : null;
            $data['template_version_id'] = $template?->current_version_id;
        }

        // Multiple segments: start_time/end_time become the bounding envelope
        // (min/max, used for display/sorting and as the source-scan window for
        // silence/crop detection — see RenderClipJob), while duration is the sum of
        // each segment's own length so it reflects what's actually kept, not the
        // envelope (which can be longer once there are gaps between segments).
        if (array_key_exists('segments', $data) && ! empty($data['segments'])) {
            $segments = collect($data['segments'])->sortBy('start')->values();
            $data['start_time'] = (float) $segments->first()['start'];
            $data['end_time'] = (float) $segments->last()['end'];
            $data['duration'] = max(0.1, $segments->sum(fn ($s) => max(0, $s['end'] - $s['start'])));
        } elseif (isset($data['start_time']) || isset($data['end_time'])) {
            $start = $data['start_time'] ?? $clip->start_time;
            $end = $data['end_time'] ?? $clip->end_time;
            $data['duration'] = max(0.1, $end - $start);
        }

        $clip->update($data);

        if (array_intersect(array_keys($data), ['title', 'caption', 'hashtags']) !== []) {
            GenerateClipEmbeddingJob::dispatch($clip->id);
        }

        if ($needsRerender) {
            $clip->update(['status' => Clip::STATUS_QUEUED, 'progress' => 0]);
            RenderClipJob::dispatch($clip->id);
        }

        return ClipResource::make($clip->fresh(['template']));
    }

    /**
     * Attach a user-supplied .srt/.ass caption file to a clip — RenderClipJob uses
     * it instead of generating captions from the transcript on the next render (an
     * .ass keeps its own embedded style as-is, a plain .srt gets styled through the
     * template pipeline). See RenderClipJob::handle() for how each is consumed.
     */
    public function uploadSubtitle(Request $request, Clip $clip)
    {
        $this->authorizeClip($request, $clip);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:2048', function ($attribute, $value, $fail) {
                $ext = strtolower($value->getClientOriginalExtension());
                if (! in_array($ext, ['srt', 'ass'], true)) {
                    $fail('The file must be a .srt or .ass caption file.');
                }
            }],
        ]);

        $file = $data['file'];
        $ext = strtolower($file->getClientOriginalExtension());
        $relativePath = Storage::disk('media')->putFileAs("subtitles/{$clip->id}", $file, "custom.{$ext}");

        $clip->update([
            'custom_subtitle_path' => $relativePath,
            'status' => Clip::STATUS_QUEUED,
            'progress' => 0,
        ]);
        RenderClipJob::dispatch($clip->id);

        return ClipResource::make($clip->fresh(['template']));
    }

    /**
     * Revert to transcript-generated captions (or none) on the next render.
     */
    public function removeSubtitle(Request $request, Clip $clip)
    {
        $this->authorizeClip($request, $clip);

        if ($clip->custom_subtitle_path) {
            Storage::disk('media')->delete($clip->custom_subtitle_path);
        }

        $clip->update([
            'custom_subtitle_path' => null,
            'status' => Clip::STATUS_QUEUED,
            'progress' => 0,
        ]);
        RenderClipJob::dispatch($clip->id);

        return ClipResource::make($clip->fresh(['template']));
    }

    /**
     * The fully merged, resolved view a client-side editor draws from: template
     * layers with this clip's layer_overrides already applied, and the merged
     * caption config. Computed via the exact same merge helpers RenderClipJob
     * renders from, so this preview can never drift from the real output.
     */
    public function previewConfig(Request $request, Clip $clip)
    {
        $this->authorizeClip($request, $clip);
        $clip->load(['template', 'templateVersion']);

        $config = $clip->templateVersion?->config ?? DefaultTemplateConfig::config();
        $captionConfig = array_merge(DefaultTemplateConfig::config()['caption'], $config['caption'] ?? [], $clip->subtitle_config ?? []);
        $layers = LayerOverrideMerger::merge($config['layers'] ?? [], $clip->layer_overrides ?? []);
        [$width, $height] = $clip->targetResolution();

        return response()->json([
            'data' => [
                'resolution' => ['width' => $width, 'height' => $height],
                'caption' => $captionConfig,
                'branding' => $config['branding'] ?? [],
                // Merged (override-applied) view, for the editor to display/edit.
                'layers' => $layers,
                // Raw template layers (no overrides), for the editor to diff edited
                // layers against when computing what to save into layer_overrides.
                'template_layers' => $config['layers'] ?? [],
            ],
        ]);
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
    public function generateSocialMetadata(
        Request $request,
        Clip $clip,
        SocialMetadataProvider $provider,
        WebContentFetcher $webFetcher,
    ) {
        $this->authorizeClip($request, $clip);

        $data = $request->validate([
            'platforms' => ['sometimes', 'array'],
            'platforms.*' => ['string', Rule::in(['tiktok', 'youtube', 'instagram', 'facebook', 'twitter', 'linkedin'])],
            'reference_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url'],
        ]);

        $platforms = $data['platforms'] ?? ['tiktok', 'instagram', 'youtube', 'facebook', 'twitter', 'linkedin'];
        // Falls back to whatever URL is already saved on the clip (e.g. set from the
        // reaction-intro panel) so this endpoint benefits from it too without the
        // caller having to resend it every time.
        $referenceUrl = array_key_exists('reference_url', $data) ? $data['reference_url'] : $clip->reference_url;
        $referenceContent = $webFetcher->fetch($referenceUrl);

        if (array_key_exists('reference_url', $data) && $data['reference_url'] !== $clip->reference_url) {
            $clip->update(['reference_url' => $data['reference_url']]);
        }

        Context::add(['project_id' => $clip->project_id, 'video_id' => $clip->video_id, 'clip_id' => $clip->id]);

        $metadata = $provider->generateMetadata($clip->load('clipCandidate'), $platforms, $referenceContent);

        // Credit the source channel deterministically rather than relying on the
        // AI prompt to remember to — see ClipCreditFormatter.
        $channelName = $clip->video?->channelName();
        foreach ($metadata as $platform => &$fields) {
            if (isset($fields['caption'])) {
                $fields['caption'] = ClipCreditFormatter::append($fields['caption'], $channelName);
            }
            if (isset($fields['description'])) {
                $fields['description'] = ClipCreditFormatter::append($fields['description'], $channelName);
            }
        }
        unset($fields);

        return response()->json(['metadata' => $metadata]);
    }

    /**
     * AI-generated provocative reaction line for the clip's own content, narrated
     * via TTS — the AI Reaction Intro cover feature (see FFmpegService::
     * renderCoverSegment()/concatSegments() and RenderClipJob::composeIntroOutro()).
     * Like generateSocialMetadata(), this only previews/saves the result — it does
     * NOT dispatch a render; the user reviews/edits the script from the clip editor
     * and applies it via the normal "Save & Re-render" (update()) flow.
     */
    public function generateReactionScript(
        Request $request,
        Clip $clip,
        ReactionScriptProvider $scripts,
        TextToSpeechProvider $tts,
        WebContentFetcher $webFetcher,
    ) {
        $this->authorizeClip($request, $clip);

        $data = $request->validate([
            'voice' => ['sometimes', 'nullable', 'string', 'max:64'],
            'reference_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url'],
        ]);

        // Falls back to whatever URL is already saved on the clip, so a URL set once
        // survives subsequent "Regenerate" clicks without having to resend it.
        $referenceUrl = array_key_exists('reference_url', $data) ? $data['reference_url'] : $clip->reference_url;
        $referenceContent = $webFetcher->fetch($referenceUrl);

        Context::add(['project_id' => $clip->project_id, 'video_id' => $clip->video_id, 'clip_id' => $clip->id]);

        $result = $scripts->generateReactionScript($clip->load(['clipCandidate', 'video.transcript']), $referenceContent);

        // TTS hits a real external quota/rate-limit often enough that it shouldn't
        // fail this whole request — the script text is the useful part the user is
        // waiting on. A failed synthesis here just leaves intro_audio_path empty;
        // RenderClipJob's own lazy-synthesize fallback (equally resilient) retries
        // it at render time.
        $audioRelative = null;
        try {
            $disk = Storage::disk('media');
            $audioRelative = "reactions-intro/{$clip->id}/audio.mp3";
            $tts->synthesize($result->text, $disk->path($audioRelative), $data['voice'] ?? null);
        } catch (\Throwable $e) {
            $audioRelative = null;
            Log::warning('Reaction intro TTS failed, saving script without narration', [
                'clip_id' => $clip->id,
                'error' => $e->getMessage(),
            ]);
        }

        $clip->update([
            'reaction_script' => $result->text,
            'reaction_tone' => $result->tone,
            'intro_voice' => $data['voice'] ?? $clip->intro_voice,
            'intro_audio_path' => $audioRelative,
            'reference_url' => array_key_exists('reference_url', $data) ? $data['reference_url'] : $clip->reference_url,
            'intro_enabled' => true,
        ]);

        return ClipResource::make($clip->fresh());
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
