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
use Illuminate\Support\Facades\Log;
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
            $maxDuration = (float) Setting::get('default_clip_duration', config('services.ai.default_clip_duration', 45));

            $droppedCount = 0;

            DB::transaction(function () use ($video, $project, $candidates, $transcript, $maxDuration, &$droppedCount) {
                ClipCandidate::where('video_id', $video->id)->delete();
                $words = $transcript->words ?? [];
                foreach ($candidates as $candidate) {
                    $attributes = $candidate->toModelAttributes($project->id, $video->id);
                    [$attributes['start_time'], $attributes['end_time']] = $this->snapToWordBoundaries(
                        $attributes['start_time'],
                        $attributes['end_time'],
                        $words
                    );
                    // Defensive — the prompt tells every provider to stay under
                    // $maxDuration, but nothing stops a model from ignoring that.
                    // Trim any overrun down to the limit rather than trusting it.
                    [$attributes['start_time'], $attributes['end_time']] = $this->clampToMaxDuration(
                        $attributes['start_time'],
                        $attributes['end_time'],
                        $maxDuration,
                        $words
                    );

                    // Defensive against a real failure mode seen in production: on a
                    // long transcript (hundreds of timestamped segments in one
                    // prompt), a model can write hook_text/title describing one
                    // moment while attaching a completely different moment's
                    // timestamp to it — every field looks individually plausible,
                    // but the resulting clip's title has nothing to do with what's
                    // actually in it. Cheap self-consistency check: hook_text is
                    // supposed to be "the exact or near-exact opening line" (see
                    // every provider's systemPrompt) — if essentially none of its
                    // words actually appear in the transcript at the claimed
                    // timestamp, the timestamp is almost certainly wrong. Drop
                    // rather than keep a clip that will visibly not match its own
                    // title.
                    if (! $this->hookTextMatchesTranscript($attributes['start_time'], $attributes['end_time'], $candidate->hookText, $words)) {
                        Log::warning('Dropping AI clip candidate — hook_text does not match the transcript at its claimed timestamp', [
                            'video_id' => $video->id,
                            'start_time' => $attributes['start_time'],
                            'end_time' => $attributes['end_time'],
                            'hook_text' => $candidate->hookText,
                        ]);
                        $droppedCount++;

                        continue;
                    }

                    $attributes['duration'] = round($attributes['end_time'] - $attributes['start_time'], 2);
                    ClipCandidate::create($attributes);
                }
            });

            $keptCount = count($candidates) - $droppedCount;

            if ($droppedCount > 0) {
                Log::warning('AnalyzeVideoJob: some candidates dropped for timestamp/text mismatch', [
                    'video_id' => $video->id,
                    'dropped' => $droppedCount,
                    'kept' => $keptCount,
                ]);
            }

            $project->update(['status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
            $processingJob->markCompleted($keptCount . ' clip candidates found');

            if ($this->autoGenerateClips && $keptCount > 0) {
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

    /**
     * Defensive backend ceiling behind the AI prompt's own duration instruction
     * (see each provider's systemPrompt()) — trims a candidate that overruns
     * $maxDuration down to the limit. A blind `min($end, $start + $maxDuration)`
     * would itself cut off mid-sentence, defeating the whole point, so this walks
     * backward from the hard cutoff to the nearest word that actually ENDS a
     * sentence (its text ends in . ! or ?) and lands there instead — the same
     * "leave a natural pause/boundary alone" idea snapToWordBoundaries() uses,
     * one level up (sentence, not word). Falls back to the nearest word boundary
     * if the excerpt has no in-range sentence end (e.g. one long run-on line),
     * and to the raw cutoff only as a last resort so a clip is never left longer
     * than $maxDuration.
     *
     * @param  array<int, array{word?: string, start?: float, end?: float}>  $words
     * @return array{0: float, 1: float}
     */
    private function clampToMaxDuration(float $start, float $end, float $maxDuration, array $words): array
    {
        if ($end - $start <= $maxDuration) {
            return [$start, $end];
        }

        $cutoff = $start + $maxDuration;

        $bestSentenceEnd = null;
        $bestWordEnd = null;
        foreach ($words as $word) {
            $wordEnd = (float) ($word['end'] ?? 0);
            if ($wordEnd <= $start || $wordEnd > $cutoff) {
                continue;
            }

            // Latest-so-far wins either way — walking the whole list rather than
            // breaking early keeps this correct regardless of whether $words is
            // guaranteed to be in chronological order.
            if ($bestWordEnd === null || $wordEnd > $bestWordEnd) {
                $bestWordEnd = $wordEnd;
            }

            if (preg_match('/[.!?]["\')\]]?$/', rtrim((string) ($word['word'] ?? '')))
                && ($bestSentenceEnd === null || $wordEnd > $bestSentenceEnd)) {
                $bestSentenceEnd = $wordEnd;
            }
        }

        return [$start, $bestSentenceEnd ?? $bestWordEnd ?? $cutoff];
    }

    /**
     * Self-consistency check between a candidate's hook_text and what the
     * transcript actually says at its claimed [start, end] — see the call
     * site's docblock for why this exists. Deliberately lenient (word overlap,
     * not exact phrase match): hook_text is allowed to be "near-exact" per the
     * prompt, and normal transcription noise (filler words, minor ASR errors)
     * would sink a stricter check. A genuine timestamp/text mismatch — the
     * hook_text belongs to a moment somewhere else entirely — clears essentially
     * no bar at all, so a low threshold still catches it without false-
     * positiving on real, honest transcription noise.
     *
     * @param  array<int, array{word?: string, start?: float, end?: float}>  $words
     */
    private function hookTextMatchesTranscript(float $start, float $end, string $hookText, array $words): bool
    {
        $normalize = fn (string $s): string => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s)) ?? '';

        $hookTokens = collect(preg_split('/\s+/u', trim($hookText)))
            ->map($normalize)
            ->filter(fn ($w) => mb_strlen($w) >= 3) // skip particles/short stopword-ish tokens
            ->unique();

        // Too short to judge fairly (e.g. a hook_text that's just "Wow!") —
        // don't block on something this check can't meaningfully evaluate.
        if ($hookTokens->count() < 2) {
            return true;
        }

        $windowTokens = collect($words)
            ->filter(fn ($w) => ($w['start'] ?? 0) >= $start - 1 && ($w['start'] ?? 0) <= $end + 1)
            ->map(fn ($w) => $normalize((string) ($w['word'] ?? '')))
            ->filter()
            ->unique();

        // No transcript content at all in the claimed window is its own
        // red flag (silence/music where the model claims dialogue) — treat
        // it the same as zero overlap rather than skipping the check.
        $overlap = $hookTokens->intersect($windowTokens)->count();

        return ($overlap / $hookTokens->count()) >= 0.25;
    }
}
