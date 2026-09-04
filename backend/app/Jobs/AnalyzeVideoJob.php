<?php

namespace App\Jobs;

use App\Exceptions\JobCancelledException;
use App\Jobs\Concerns\ChecksCancellation;
use App\Models\ClipCandidate;
use App\Models\ProcessingJob;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Transcript;
use App\Models\Video;
use App\Services\AI\Contracts\ContentAnalysisProvider;
use App\Services\AI\Contracts\TranscriptionProvider;
use App\Services\Video\ClipGenerationService;
use App\Services\Video\FFmpegService;
use App\Services\Video\SrtTranscriptionBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AnalyzeVideoJob implements ShouldQueue
{
    use ChecksCancellation, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 1800;

    /**
     * @param  bool  $autoGenerateClips  Whether to auto-pick and render clips once
     *   candidates are found, with no manual "Generate Clips" click needed. On by
     *   default for the regular single-project flow. The batch autobot passes
     *   false here and does its own clip selection + in-process sequential
     *   rendering afterward (ProcessBatchItemJob) — leaving this on there would
     *   render every clip twice, once queued from here and once in-process there.
     */
    public function __construct(public int $projectId, public int $videoId, public bool $autoGenerateClips = true)
    {
    }

    public function handle(
        FFmpegService $ffmpeg,
        TranscriptionProvider $transcription,
        ContentAnalysisProvider $analysis,
        SrtTranscriptionBuilder $srtBuilder,
        ClipGenerationService $clipGeneration,
    ): void {
        $project = Project::findOrFail($this->projectId);
        $video = Video::findOrFail($this->videoId);
        // See App\Services\AI\Logging — every AI provider call below reads this back
        // to tag its ai_request_logs row, without threading ids through every
        // Contract method signature.
        Context::add(['project_id' => $project->id, 'video_id' => $video->id]);
        $disk = Storage::disk('media');
        $fullVideoPath = $disk->path($video->disk_path);

        $processingJob = ProcessingJob::create([
            'project_id' => $project->id,
            'video_id' => $video->id,
            'type' => 'analyze',
            'status' => ProcessingJob::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $project->update(['status' => Project::STATUS_TRANSCRIBING, 'failure_reason' => null]);

            $captionsPath = $video->metadata['captions_srt_path'] ?? null;
            if ($captionsPath && $disk->exists($captionsPath)) {
                // Real captions already came down with the video (e.g. YouTube) — free,
                // fast, and no re-transcription needed. Skip audio extraction entirely.
                $processingJob->markProgress(25, 'Using captions from source...');
                $result = $srtBuilder->build(
                    $disk->get($captionsPath),
                    $video->metadata['captions_language'] ?? 'unknown'
                );
            } else {
                $this->abortIfCancelled($processingJob);
                $processingJob->markProgress(10, 'Extracting audio...');
                $audioRelative = "videos/{$video->id}/audio.wav";
                $ffmpeg->extractAudio($fullVideoPath, $disk->path($audioRelative));
                $video->update(['audio_path' => $audioRelative]);

                $this->abortIfCancelled($processingJob);
                $processingJob->markProgress(30, 'Transcribing speech...');
                $result = $transcription->transcribe(
                    $disk->path($audioRelative),
                    $video->metadata['language'] ?? null,
                    fn () => $this->isCancelled($processingJob),
                );
            }

            $transcript = Transcript::updateOrCreate(['video_id' => $video->id], $result->toArray());

            $this->abortIfCancelled($processingJob);
            $project->update(['status' => Project::STATUS_ANALYZING]);
            $processingJob->markProgress(55, 'Detecting scenes...');
            $scenes = $analysis->detectScenes($fullVideoPath, (float) $video->duration_seconds);

            $this->abortIfCancelled($processingJob);
            $processingJob->markProgress(75, 'Finding interesting moments...');
            $candidates = $analysis->analyzeMoments(
                $transcript,
                $scenes,
                (float) $video->duration_seconds,
                fn () => $this->isCancelled($processingJob),
            );

            // All-or-nothing: a provider occasionally returns a candidate that fails
            // to save (e.g. a DB constraint) partway through the batch. Without the
            // transaction that leaves a partial candidate set behind — which then
            // makes any future retry think analysis already finished (candidates
            // exist) and skip straight to rendering off incomplete data instead of
            // re-running the analysis that actually failed.
            DB::transaction(function () use ($video, $project, $candidates, $transcript) {
                ClipCandidate::where('video_id', $video->id)->delete();
                $words = $transcript->words ?? [];
                foreach ($candidates as $candidate) {
                    $attributes = $candidate->toModelAttributes($project->id, $video->id);
                    [$attributes['start_time'], $attributes['end_time']] = $this->snapToWordBoundaries(
                        $attributes['start_time'],
                        $attributes['end_time'],
                        $words
                    );
                    $attributes['duration'] = round($attributes['end_time'] - $attributes['start_time'], 2);
                    ClipCandidate::create($attributes);
                }
            });

            $project->update(['status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
            $processingJob->markCompleted(count($candidates) . ' clip candidates found');

            if ($this->autoGenerateClips && count($candidates) > 0) {
                $clips = $clipGeneration->selectAndCreateClips($project, [
                    'mode' => 'top_5',
                    'template_id' => Setting::get('default_template_id'),
                    'aspect_ratio' => '9:16',
                    'subtitle_language' => $transcript->language ?: 'en',
                    'subtitles_enabled' => true,
                ]);

                if ($clips->isNotEmpty()) {
                    foreach ($clips as $clip) {
                        RenderClipJob::dispatch($clip->id);
                    }
                    $project->update(['status' => Project::STATUS_RENDERING]);
                }
            }
        } catch (JobCancelledException $e) {
            // Already marked ProcessingJob::STATUS_CANCELLED by whoever cancelled it
            // (the /cancel endpoint or the stalled-job watchdog) — don't overwrite
            // that back to "failed", and don't rethrow (that would count as a queue
            // failure and consume a retry attempt for something the user asked for).
            $project->update(['status' => Project::STATUS_FAILED, 'failure_reason' => $e->getMessage()]);
        } catch (Throwable $e) {
            $project->update(['status' => Project::STATUS_FAILED, 'failure_reason' => $e->getMessage()]);
            $processingJob->markFailed($e->getMessage());

            throw $e;
        }
    }

    /**
     * An AI-picked start/end (free-form seconds, read off a formatted transcript)
     * regularly lands a fraction of a second inside a word rather than exactly on
     * its boundary — the model has no frame-accurate sense of timing, only the
     * text it was shown. Rendering that verbatim cuts the clip mid-word. Pull each
     * boundary out to the edge of whichever word it falls inside, using the
     * transcript's real per-word timestamps — a gap between words (natural pause)
     * is already a safe cut point and is left untouched.
     *
     * @param  array<int, array{word?: string, start?: float, end?: float}>  $words
     * @return array{0: float, 1: float}
     */
    private function snapToWordBoundaries(float $start, float $end, array $words): array
    {
        foreach ($words as $word) {
            $wordStart = (float) ($word['start'] ?? 0);
            $wordEnd = (float) ($word['end'] ?? 0);

            if ($start > $wordStart && $start < $wordEnd) {
                $start = $wordStart;
            }

            if ($end > $wordStart && $end < $wordEnd) {
                $end = $wordEnd;
            }
        }

        return [$start, $end];
    }
}
