<?php

namespace App\Jobs;

use App\Exceptions\JobCancelledException;
use App\Jobs\Concerns\ChecksCancellation;
use App\Models\ClipCandidate;
use App\Models\ProcessingJob;
use App\Models\Project;
use App\Models\Transcript;
use App\Models\Video;
use App\Services\AI\Contracts\ContentAnalysisProvider;
use App\Services\AI\Contracts\TranscriptionProvider;
use App\Services\AI\DTOs\TranscriptionResult;
use App\Services\Video\FFmpegService;
use App\Services\Video\SrtParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AnalyzeVideoJob implements ShouldQueue
{
    use ChecksCancellation, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 1800;

    public function __construct(public int $projectId, public int $videoId)
    {
    }

    public function handle(
        FFmpegService $ffmpeg,
        TranscriptionProvider $transcription,
        ContentAnalysisProvider $analysis,
    ): void {
        $project = Project::findOrFail($this->projectId);
        $video = Video::findOrFail($this->videoId);
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
                $result = $this->transcriptionResultFromSrt(
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

            ClipCandidate::where('video_id', $video->id)->delete();
            foreach ($candidates as $candidate) {
                ClipCandidate::create($candidate->toModelAttributes($project->id, $video->id));
            }

            $project->update(['status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
            $processingJob->markCompleted(count($candidates) . ' clip candidates found');
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

    private function transcriptionResultFromSrt(string $srtContent, string $language): TranscriptionResult
    {
        $segments = SrtParser::parse($srtContent);

        $words = [];
        $fullTextParts = [];
        foreach ($segments as $seg) {
            $fullTextParts[] = $seg['text'];

            // Source captions are segment-level only; interpolate even word spacing
            // within each segment so word-by-word caption highlighting still works.
            $wordList = preg_split('/\s+/', $seg['text']);
            $wordCount = max(count($wordList), 1);
            $perWord = ($seg['end'] - $seg['start']) / $wordCount;
            foreach ($wordList as $i => $word) {
                $wStart = $seg['start'] + $i * $perWord;
                $words[] = [
                    'word' => $word,
                    'start' => round($wStart, 2),
                    'end' => round($wStart + $perWord, 2),
                    'speaker' => 'A',
                ];
            }
        }

        return new TranscriptionResult(
            language: $language,
            fullText: implode(' ', $fullTextParts),
            segments: $segments,
            words: $words,
            speakers: [['id' => 'A', 'label' => 'Speaker A']],
            provider: 'source_captions',
        );
    }
}
