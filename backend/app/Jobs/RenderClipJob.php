<?php

namespace App\Jobs;

use App\Exceptions\JobCancelledException;
use App\Jobs\Concerns\ChecksCancellation;
use App\Models\Clip;
use App\Models\Project;
use App\Models\ProcessingJob;
use App\Models\Subtitle;
use App\Models\VideoBatchItem;
use App\Services\AI\Contracts\ImageGenerationProvider;
use App\Services\AI\Contracts\ReframingProvider;
use App\Services\AI\Contracts\TextToSpeechProvider;
use App\Services\Social\AutoPublishScheduler;
use App\Services\Video\AspectRatio;
use App\Services\Video\DefaultTemplateConfig;
use App\Services\Video\FFmpegService;
use App\Services\Video\LayerOverrideMerger;
use App\Services\Video\SilenceTrimmer;
use App\Services\Video\SrtParser;
use App\Services\Video\SubtitleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RenderClipJob implements ShouldQueue
{
    use ChecksCancellation, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 1800;

    public function __construct(public int $clipId)
    {
    }

    public function handle(
        FFmpegService $ffmpeg,
        SubtitleService $subtitleService,
        ReframingProvider $reframing,
        AutoPublishScheduler $publishScheduler,
        SilenceTrimmer $silenceTrimmer,
        TextToSpeechProvider $tts,
        ImageGenerationProvider $imageGen,
    ): void {
        $clip = Clip::with(['video', 'templateVersion', 'clipCandidate'])->findOrFail($this->clipId);
        $disk = Storage::disk('media');

        $processingJob = ProcessingJob::create([
            'project_id' => $clip->project_id,
            'video_id' => $clip->video_id,
            'clip_id' => $clip->id,
            'type' => 'render_clip',
            'status' => ProcessingJob::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $clip->update(['status' => Clip::STATUS_RENDERING, 'progress' => 5, 'failure_reason' => null]);
            $processingJob->markProgress(5, 'Preparing render...');

            $config = $clip->templateVersion?->config ?? DefaultTemplateConfig::config();
            $captionConfig = array_merge(DefaultTemplateConfig::config()['caption'], $config['caption'] ?? [], $clip->subtitle_config ?? []);
            [$targetWidth, $targetHeight] = AspectRatio::resolution($clip->aspect_ratio);

            $video = $clip->video;
            $sourcePath = $disk->path($video->disk_path);

            // A user-supplied caption file is assumed timed against this clip's own
            // (uncut) duration, not the original source video, and a raw .ass's
            // override tags can't be generically remapped the way transcript word
            // timestamps can — so when one is present, silence removal is skipped
            // entirely for this render (same treatment as a reaction clip's webcam
            // track below) rather than risk silently desyncing captions we can't
            // safely retime.
            $customSubtitlePath = $clip->custom_subtitle_path && $disk->exists($clip->custom_subtitle_path)
                ? $disk->path($clip->custom_subtitle_path)
                : null;

            $segments = $this->resolveSegments($clip);
            $clipStart = (float) $segments[0]['start'];
            $clipEnd = (float) $segments[count($segments) - 1]['end'];
            $clipDuration = max(0.1, $clipEnd - $clipStart);

            // Reaction clips mix the source's audio with a separately-recorded
            // webcam track (renderReactionClip) that isn't silence-trimmed itself —
            // cutting gaps out of the source alone would drift it out of sync with
            // that untouched webcam recording, so silence removal (and multi-segment
            // gap removal, below, which reuses the exact same keepIntervals/concat
            // machinery) only applies to plain (non-reaction) clips; resolveSegments()
            // already collapses a reaction clip to one segment regardless of any
            // multi-segment selection stored on it, for the same reason.
            //
            // Multiple user-picked segments and AI-detected silence are the same
            // *kind* of thing from here on: both are just holes in keepIntervals.
            // Each segment gets its own silence pass (independently, in its own
            // absolute source-time window) and the results are flattened into one
            // list, clip-relative to the overall envelope [$clipStart, $clipEnd] —
            // exactly the shape extractWithoutSilence()/remapWords()/
            // remapKeyframes() already expect, so nothing downstream needs to know
            // segments exist at all.
            $keepIntervals = [];
            if (! $clip->webcam_path && ! $customSubtitlePath) {
                $this->abortIfCancelled($processingJob);
                $processingJob->markProgress(10, 'Detecting silence...');
                foreach ($segments as $segment) {
                    $segStart = (float) $segment['start'];
                    $segDuration = max(0.01, (float) $segment['end'] - $segStart);
                    $silences = $ffmpeg->detectSilence(
                        $sourcePath,
                        $segStart,
                        $segDuration,
                        SilenceTrimmer::NOISE_THRESHOLD_DB,
                        SilenceTrimmer::MIN_SILENCE_SECONDS
                    );
                    foreach ($silenceTrimmer->computeKeepIntervals($silences, $segDuration) as $k) {
                        $keepIntervals[] = [
                            'start' => ($segStart - $clipStart) + $k['start'],
                            'end' => ($segStart - $clipStart) + $k['end'],
                        ];
                    }
                }
            } else {
                foreach ($segments as $segment) {
                    $keepIntervals[] = ['start' => (float) $segment['start'] - $clipStart, 'end' => (float) $segment['end'] - $clipStart];
                }
            }
            $hasSilenceCuts = $silenceTrimmer->hasCuts($keepIntervals, $clipDuration);

            // Split-screen reaction layouts only give the source clip half the frame,
            // which ReframingProvider::detectCropKeyframes() can't target — it only
            // understands the three whole-frame aspect ratios. Skip smart-pan crop
            // for those (FFmpegService falls back to a plain center-crop instead);
            // PIP layouts still fill the whole frame, so they keep smart-pan as-is.
            $isSplitReaction = in_array($clip->reaction_layout, ['split_top_bottom', 'split_side_by_side'], true);

            // Manual crop: the user dragged/sized their own crop keyframes in the
            // editor (envelope-relative time, exactly like raw AI keyframes before
            // remapping — see LayerCompositionService-adjacent docs) instead of
            // asking ReframingProvider for smart-pan ones. Applied identically from
            // here on (still remapped around cuts below), the only difference is
            // where $keyframeArrays comes from and that crop_config is NOT
            // overwritten afterward — unlike the AI path, this is user input that
            // must survive to the next render unchanged, not regenerated output.
            $manualCrop = ($clip->crop_config['mode'] ?? null) === 'manual' && ! empty($clip->crop_config['keyframes']);

            $keyframeArrays = [];
            if (! $isSplitReaction) {
                $this->abortIfCancelled($processingJob);
                if ($manualCrop) {
                    $keyframeArrays = $clip->crop_config['keyframes'];
                } else {
                    $processingJob->markProgress(15, 'Calculating smart crop...');
                    $keyframes = $reframing->detectCropKeyframes(
                        $sourcePath,
                        $clipStart,
                        $clipEnd,
                        (int) $video->width,
                        (int) $video->height,
                        $clip->aspect_ratio
                    );
                    $keyframeArrays = array_map(fn ($k) => $k->toArray(), $keyframes);
                }
                if ($hasSilenceCuts) {
                    $keyframeArrays = $silenceTrimmer->remapKeyframes($keyframeArrays, $keepIntervals);
                }
                if (! $manualCrop) {
                    $clip->update(['crop_config' => ['mode' => 'smart', 'keyframes' => $keyframeArrays]]);
                }
            }

            // Cut the detected silent gaps out of the source now that both the crop
            // keyframes (above) and, shortly, the caption words are computed against
            // — and then remapped off of — the original timeline. Everything past
            // this point renders against the shortened, silence-free file.
            $renderSourcePath = $sourcePath;
            $renderClipStart = $clipStart;
            $renderClipEnd = $clipEnd;
            $noSilenceRelative = null;
            if ($hasSilenceCuts) {
                $this->abortIfCancelled($processingJob);
                $processingJob->markProgress(25, 'Removing silence...');
                $noSilenceRelative = "clips/{$clip->id}/no_silence.mp4";
                $ffmpeg->extractWithoutSilence($sourcePath, $disk->path($noSilenceRelative), $clipStart, $keepIntervals);
                $renderSourcePath = $disk->path($noSilenceRelative);
                $renderClipStart = 0.0;
                $renderClipEnd = $silenceTrimmer->totalDuration($keepIntervals);
            }

            $assPath = null;
            if ($customSubtitlePath) {
                $this->abortIfCancelled($processingJob);
                $processingJob->markProgress(35, 'Using custom captions...');

                $ext = strtolower(pathinfo($customSubtitlePath, PATHINFO_EXTENSION));

                if ($ext === 'ass') {
                    // Carries its own style (fonts, colors, positions) — burned in
                    // exactly as provided, template caption settings don't apply.
                    $assPath = $customSubtitlePath;
                } elseif ($ext === 'srt') {
                    // Plain .srt has no style of its own — run it through the same
                    // template pipeline (color/size/position/background/animation)
                    // as transcript-generated captions so it still looks
                    // professional. No per-word timestamps exist in an .srt, so
                    // 'words' stays empty per segment; toAss() already handles that
                    // by rendering the whole line styled but without per-word
                    // highlight, exactly like a segment with highlighting disabled.
                    $customSegments = array_map(fn (array $seg) => [
                        'start' => $seg['start'],
                        'end' => $seg['end'],
                        'text' => $seg['text'],
                        'words' => [],
                    ], SrtParser::parse(file_get_contents($customSubtitlePath)));

                    $ass = $subtitleService->toAss($customSegments, $captionConfig, $targetWidth, $targetHeight);
                    $assRelative = "subtitles/{$clip->id}/custom.ass";
                    $disk->put($assRelative, $ass);
                    $assPath = $disk->path($assRelative);
                }
            } elseif ($clip->subtitles_enabled && $video->transcript) {
                $this->abortIfCancelled($processingJob);
                $processingJob->markProgress(35, 'Generating captions...');

                $words = $video->transcript->words ?? [];
                $captionClipStart = $clipStart;
                $captionClipEnd = $clipEnd;
                if ($hasSilenceCuts) {
                    $words = $silenceTrimmer->remapWords($words, $clipStart, $clipEnd, $keepIntervals);
                    $captionClipStart = 0.0;
                    $captionClipEnd = $renderClipEnd;
                }

                $segments = $subtitleService->buildClipSegments(
                    $words,
                    $captionClipStart,
                    $captionClipEnd,
                    (int) ($captionConfig['words_per_line'] ?? 3)
                );

                $srt = $subtitleService->toSrt($segments);
                $ass = $subtitleService->toAss($segments, $captionConfig, $targetWidth, $targetHeight);

                $srtRelative = "subtitles/{$clip->id}/{$clip->subtitle_language}.srt";
                $assRelative = "subtitles/{$clip->id}/{$clip->subtitle_language}.ass";
                $disk->put($srtRelative, $srt);
                $disk->put($assRelative, $ass);

                Subtitle::updateOrCreate(
                    ['clip_id' => $clip->id, 'language' => $clip->subtitle_language],
                    ['segments' => $segments, 'srt_path' => $srtRelative, 'ass_path' => $assRelative]
                );

                $assPath = $disk->path($assRelative);
            }

            $watermarkPath = null;
            if (! empty($config['branding']['watermark_path'])) {
                $watermarkPath = $disk->path($config['branding']['watermark_path']);
            }
            $watermarkOpacity = (float) ($config['branding']['watermark_opacity'] ?? 0.8);

            // Absent/v1 config['layers'] (every template/clip before this feature)
            // merges down to an empty array, so pre-existing renders are unaffected.
            $layers = LayerOverrideMerger::merge($config['layers'] ?? [], $clip->layer_overrides ?? []);
            $resolveLayerPath = fn (string $relative) => $disk->path($relative);

            $this->abortIfCancelled($processingJob);
            $processingJob->markProgress(55, 'Rendering video...');
            $clip->update(['progress' => 55]);

            $outputRelative = "clips/{$clip->id}/output.mp4";
            if ($clip->webcam_path) {
                $ffmpeg->renderReactionClip(
                    $renderSourcePath,
                    $disk->path($clip->webcam_path),
                    $disk->path($outputRelative),
                    $renderClipStart,
                    $renderClipEnd,
                    $targetWidth,
                    $targetHeight,
                    $clip->reaction_layout,
                    $keyframeArrays,
                    $assPath,
                    $watermarkPath,
                    $watermarkOpacity,
                    $layers,
                    $resolveLayerPath,
                );
            } else {
                $ffmpeg->renderClip(
                    $renderSourcePath,
                    $disk->path($outputRelative),
                    $renderClipStart,
                    $renderClipEnd,
                    $targetWidth,
                    $targetHeight,
                    $keyframeArrays,
                    $assPath,
                    $watermarkPath,
                    $watermarkOpacity,
                    $layers,
                    $resolveLayerPath,
                    $config['video_region'] ?? null,
                    (string) ($config['canvas_background_color'] ?? '#000000'),
                );
            }

            if ($noSilenceRelative) {
                $disk->delete($noSilenceRelative);
            }

            $this->abortIfCancelled($processingJob);
            $processingJob->markProgress(90, 'Generating thumbnail...');
            $thumbRelative = "clips/{$clip->id}/thumbnail.jpg";
            // Always grabbed from the main clip content (not the intro/outro cover
            // below), so the thumbnail stays representative of the clip itself.
            $ffmpeg->generateThumbnail($disk->path($outputRelative), $disk->path($thumbRelative), 0.3);

            $finalOutputRelative = $outputRelative;
            if ($clip->intro_enabled || $clip->outro_enabled) {
                $this->abortIfCancelled($processingJob);
                $processingJob->markProgress(93, 'Building intro/outro...');
                $finalOutputRelative = $this->composeIntroOutro($clip, $ffmpeg, $tts, $imageGen, $disk, $outputRelative, $targetWidth, $targetHeight);
            }

            $clip->update([
                'status' => Clip::STATUS_COMPLETED,
                'progress' => 100,
                'output_path' => $finalOutputRelative,
                'thumbnail_path' => $thumbRelative,
                'output_size_bytes' => $disk->exists($finalOutputRelative) ? $disk->size($finalOutputRelative) : null,
                'rendered_at' => now(),
            ]);

            $processingJob->markCompleted('Clip rendered');
            $this->settleProjectStatus($clip->project_id, $publishScheduler);
        } catch (JobCancelledException $e) {
            // See the matching catch in AnalyzeVideoJob: already marked cancelled by
            // whoever stopped it, don't overwrite that or retry.
            $clip->update(['status' => Clip::STATUS_FAILED, 'failure_reason' => $e->getMessage()]);
            $this->settleProjectStatus($clip->project_id, $publishScheduler);
        } catch (Throwable $e) {
            $clip->update(['status' => Clip::STATUS_FAILED, 'failure_reason' => $e->getMessage()]);
            $processingJob->markFailed($e->getMessage());
            $this->settleProjectStatus($clip->project_id, $publishScheduler);

            throw $e;
        }
    }

    /**
     * Builds the AI reaction intro cover (cover frame grabbed from the main clip +
     * cached/lazily-synthesized TTS narration of clip.reaction_script + the script
     * as an overlay caption) and/or a static outro card, then concatenates whichever
     * of [intro, main clip, outro] apply into one final file via
     * FFmpegService::concatSegments(). Returns the disk-relative path of that final
     * file — or, if neither intro nor outro actually produced a segment (e.g.
     * intro_enabled but reaction_script was never generated), $outputRelative
     * unchanged, so callers always get back a valid, playable clip path.
     */
    private function composeIntroOutro(
        Clip $clip,
        FFmpegService $ffmpeg,
        TextToSpeechProvider $tts,
        ImageGenerationProvider $imageGen,
        Filesystem $disk,
        string $outputRelative,
        int $targetWidth,
        int $targetHeight,
    ): string {
        $segments = [$disk->path($outputRelative)];
        $introIndex = null;
        $tmpFiles = [];

        if ($clip->intro_enabled && $clip->reaction_script) {
            // TTS hits a real external quota/rate-limit often enough (each upstream
            // credential behind whichever provider is configured has its own) that
            // it can't be allowed to fail the whole render — a clip is still a
            // perfectly good clip without its intro narration. Anything that goes
            // wrong while building the intro (TTS itself, or the cover-frame/segment
            // render right after) is caught here and just skips the intro segment;
            // the outro stage below and the main clip output are unaffected.
            try {
                $introAudioRelative = $clip->intro_audio_path;
                if (! $introAudioRelative || ! $disk->exists($introAudioRelative)) {
                    $introAudioRelative = "clips/{$clip->id}/intro_audio.mp3";
                    $tts->synthesize($clip->reaction_script, $disk->path($introAudioRelative), $clip->intro_voice);
                    $clip->update(['intro_audio_path' => $introAudioRelative]);
                }
                $introAudioPath = $disk->path($introAudioRelative);
                $introDuration = ($ffmpeg->probeDuration($introAudioPath) ?? 4.0) + 0.3;

                $coverFrameRelative = "clips/{$clip->id}/intro_cover.jpg";
                // Cached like intro_audio_path above — only (re)generated when empty
                // or missing, cleared by ClipController::update() whenever
                // reaction_script changes, so a real image-gen provider isn't called
                // on every re-render (e.g. a caption-only "Save & Re-render").
                if (! $clip->intro_cover_path || ! $disk->exists($clip->intro_cover_path)) {
                    $fallbackFrameRelative = "clips/{$clip->id}/intro_cover_fallback.jpg";
                    $ffmpeg->generateThumbnail($disk->path($outputRelative), $disk->path($fallbackFrameRelative), 0.0);
                    $imageGen->generateCoverImage(
                        $this->buildCoverPrompt($clip),
                        $disk->path($coverFrameRelative),
                        $disk->path($fallbackFrameRelative)
                    );
                    $tmpFiles[] = $disk->path($fallbackFrameRelative);
                    $clip->update(['intro_cover_path' => $coverFrameRelative]);
                } else {
                    $coverFrameRelative = $clip->intro_cover_path;
                }

                $introSegmentRelative = "clips/{$clip->id}/intro_segment.mp4";
                $ffmpeg->renderCoverSegment(
                    $disk->path($coverFrameRelative),
                    $introAudioPath,
                    $disk->path($introSegmentRelative),
                    $targetWidth,
                    $targetHeight,
                    $introDuration,
                    $clip->reaction_script,
                );

                $introIndex = 0;
                array_unshift($segments, $disk->path($introSegmentRelative));
                $tmpFiles[] = $disk->path($introSegmentRelative);
            } catch (Throwable $e) {
                Log::warning('Reaction intro build failed, rendering clip without it', [
                    'clip_id' => $clip->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($clip->outro_enabled) {
            $mainDuration = $ffmpeg->probeDuration($disk->path($outputRelative)) ?? 0.0;
            $outroFrameRelative = "clips/{$clip->id}/outro_cover.jpg";
            $ffmpeg->generateThumbnail($disk->path($outputRelative), $disk->path($outroFrameRelative), max(0.0, $mainDuration - 0.2));

            $outroSegmentRelative = "clips/{$clip->id}/outro_segment.mp4";
            $ffmpeg->renderCoverSegment(
                $disk->path($outroFrameRelative),
                null,
                $disk->path($outroSegmentRelative),
                $targetWidth,
                $targetHeight,
                2.5,
                'Follow for more',
            );

            $segments[] = $disk->path($outroSegmentRelative);
            $tmpFiles[] = $disk->path($outroFrameRelative);
            $tmpFiles[] = $disk->path($outroSegmentRelative);
        }

        if ($introIndex === null && count($segments) === 1) {
            // Neither stage actually produced a segment (e.g. outro_enabled=false and
            // intro_enabled=true but reaction_script was empty) — nothing to compose.
            return $outputRelative;
        }

        $finalRelative = "clips/{$clip->id}/output_final.mp4";
        $ffmpeg->concatSegments($segments, $disk->path($finalRelative), $targetWidth, $targetHeight);

        foreach ($tmpFiles as $tmp) {
            @unlink($tmp);
        }

        return $finalRelative;
    }

    /**
     * Short text-to-image prompt for the reaction-intro cover — built from whatever
     * content context is available. Ignored entirely by MockImageGenerationProvider
     * (which just reuses the video frame instead), only used by a real
     * ImageGenerationProvider.
     */
    private function buildCoverPrompt(Clip $clip): string
    {
        $candidate = $clip->clipCandidate;

        return sprintf(
            'Eye-catching vertical video cover thumbnail, dramatic and high-contrast, no text or logos. '
            . 'Illustrates: %s. Mood/type: %s.',
            $clip->reaction_script ?: ($candidate?->hook_text ?? $clip->title ?: 'a short video clip'),
            $candidate?->moment_type ?? 'engaging moment'
        );
    }

    /**
     * Normalizes a clip's multi-segment selection (clips.segments — a "jump cut"
     * multi-trim, see its migration) into a sorted, non-empty list of absolute
     * source-video {start,end} windows. A clip with no segments (every clip before
     * this feature, and any clip that's just been simply trimmed) synthesizes
     * exactly one segment from start_time/end_time, which is byte-for-byte the
     * single window the rest of this job already worked with before segments
     * existed. Reaction clips always collapse to one segment regardless of what's
     * stored — see the caller's docblock for why.
     *
     * @return list<array{start: float, end: float}>
     */
    private function resolveSegments(Clip $clip): array
    {
        if ($clip->webcam_path || empty($clip->segments)) {
            return [['start' => (float) $clip->start_time, 'end' => (float) $clip->end_time]];
        }

        $segments = collect($clip->segments)
            ->map(fn ($s) => ['start' => (float) $s['start'], 'end' => (float) $s['end']])
            ->filter(fn ($s) => $s['end'] > $s['start'])
            ->sortBy('start')
            ->values()
            ->all();

        return $segments ?: [['start' => (float) $clip->start_time, 'end' => (float) $clip->end_time]];
    }

    /**
     * Once every clip for a project has left the queued/rendering state, move the
     * project out of "rendering" so the frontend stops polling for progress — and,
     * for a regular (non-batch) project, auto-schedule publishing for whichever
     * clips completed, same as the batch autobot already does for its own items.
     * Skipped for batch-created projects: ProcessBatchItemJob schedules publishing
     * itself right after its own render loop, so doing it here too would either
     * race it or double-schedule.
     */
    private function settleProjectStatus(int $projectId, AutoPublishScheduler $publishScheduler): void
    {
        $stillActive = Clip::where('project_id', $projectId)
            ->whereIn('status', [Clip::STATUS_QUEUED, Clip::STATUS_RENDERING])
            ->exists();

        if ($stillActive) {
            return;
        }

        $project = Project::find($projectId);
        if (! $project || $project->status !== Project::STATUS_RENDERING) {
            return;
        }

        $project->update(['status' => Project::STATUS_COMPLETED]);

        if (! VideoBatchItem::where('project_id', $projectId)->exists()) {
            $publishScheduler->scheduleForProject($project);
        }
    }
}
