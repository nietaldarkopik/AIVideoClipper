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

            $this->abortIfCancelled($processingJob);
            $processingJob->markProgress(15, 'Calculating smart crop...');
            $keyframes = $reframing->detectCropKeyframes(
                $sourcePath,
                (float) $clip->start_time,
                (float) $clip->end_time,
                (int) $video->width,
                (int) $video->height,
                $clip->aspect_ratio
            );
            $keyframeArrays = array_map(fn ($k) => $k->toArray(), $keyframes);
            $clip->update(['crop_config' => ['mode' => 'smart', 'keyframes' => $keyframeArrays]]);

            $assPath = null;
            if ($clip->subtitles_enabled && $video->transcript) {
                $this->abortIfCancelled($processingJob);
                $processingJob->markProgress(35, 'Generating captions...');

                $segments = $subtitleService->buildClipSegments(
                    $video->transcript->words ?? [],
                    (float) $clip->start_time,
                    (float) $clip->end_time,
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
            $ffmpeg->renderClip(
                $sourcePath,
                $disk->path($outputRelative),
                (float) $clip->start_time,
                (float) $clip->end_time,
                $targetWidth,
                $targetHeight,
                $keyframeArrays,
                $assPath,
                $watermarkPath,
                $watermarkOpacity,
            );

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
