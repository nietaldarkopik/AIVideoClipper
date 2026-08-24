<?php

namespace App\Jobs;

use App\Exceptions\JobCancelledException;
use App\Jobs\Concerns\ChecksCancellation;
use App\Models\Clip;
use App\Models\Project;
use App\Models\ProcessingJob;
use App\Models\Subtitle;
use App\Models\VideoBatchItem;
use App\Services\AI\Contracts\ReframingProvider;
use App\Services\Social\AutoPublishScheduler;
use App\Services\Video\AspectRatio;
use App\Services\Video\DefaultTemplateConfig;
use App\Services\Video\FFmpegService;
use App\Services\Video\SilenceTrimmer;
use App\Services\Video\SubtitleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
    ): void {
        $clip = Clip::with(['video', 'templateVersion'])->findOrFail($this->clipId);
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
            $clipStart = (float) $clip->start_time;
            $clipEnd = (float) $clip->end_time;
            $clipDuration = max(0.1, $clipEnd - $clipStart);

            // Reaction clips mix the source's audio with a separately-recorded
            // webcam track (renderReactionClip) that isn't silence-trimmed itself —
            // cutting gaps out of the source alone would drift it out of sync with
            // that untouched webcam recording, so silence removal only applies to
            // plain (non-reaction) clips.
            $keepIntervals = [['start' => 0.0, 'end' => $clipDuration]];
            if (! $clip->webcam_path) {
                $this->abortIfCancelled($processingJob);
                $processingJob->markProgress(10, 'Detecting silence...');
                $silences = $ffmpeg->detectSilence(
                    $sourcePath,
                    $clipStart,
                    $clipDuration,
                    SilenceTrimmer::NOISE_THRESHOLD_DB,
                    SilenceTrimmer::MIN_SILENCE_SECONDS
                );
                $keepIntervals = $silenceTrimmer->computeKeepIntervals($silences, $clipDuration);
            }
            $hasSilenceCuts = $silenceTrimmer->hasCuts($keepIntervals, $clipDuration);

            // Split-screen reaction layouts only give the source clip half the frame,
            // which ReframingProvider::detectCropKeyframes() can't target — it only
            // understands the three whole-frame aspect ratios. Skip smart-pan crop
            // for those (FFmpegService falls back to a plain center-crop instead);
            // PIP layouts still fill the whole frame, so they keep smart-pan as-is.
            $isSplitReaction = in_array($clip->reaction_layout, ['split_top_bottom', 'split_side_by_side'], true);

            $keyframeArrays = [];
            if (! $isSplitReaction) {
                $this->abortIfCancelled($processingJob);
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
                if ($hasSilenceCuts) {
                    $keyframeArrays = $silenceTrimmer->remapKeyframes($keyframeArrays, $keepIntervals);
                }
                $clip->update(['crop_config' => ['mode' => 'smart', 'keyframes' => $keyframeArrays]]);
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
            if ($clip->subtitles_enabled && $video->transcript) {
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
                );
            }

            if ($noSilenceRelative) {
                $disk->delete($noSilenceRelative);
            }

            $this->abortIfCancelled($processingJob);
            $processingJob->markProgress(90, 'Generating thumbnail...');
            $thumbRelative = "clips/{$clip->id}/thumbnail.jpg";
            $ffmpeg->generateThumbnail($disk->path($outputRelative), $disk->path($thumbRelative), 0.3);

            $clip->update([
                'status' => Clip::STATUS_COMPLETED,
                'progress' => 100,
                'output_path' => $outputRelative,
                'thumbnail_path' => $thumbRelative,
                'output_size_bytes' => $disk->exists($outputRelative) ? $disk->size($outputRelative) : null,
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
