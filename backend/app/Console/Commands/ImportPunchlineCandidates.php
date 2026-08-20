<?php

namespace App\Console\Commands;

use App\Models\ClipCandidate;
use App\Models\Project;
use App\Models\Transcript;
use App\Services\AI\DTOs\ClipCandidateData;
use App\Services\Video\SrtTranscriptionBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Writes clip candidates picked by hand (by reading a video's .srt in a separate
 * Claude conversation — the "cowork" step; this app never calls any AI here) into
 * the same ClipCandidate rows AnalyzeVideoJob would have produced automatically.
 * Once this runs, the project behaves exactly as if "Analyze Video" had found
 * these moments itself: the existing "Generate Clips" flow (render, face
 * tracking, captions, auto-publish) takes over unchanged.
 *
 * Input is a JSON array, one object per punchline:
 * [{
 *   "start_time": 123.4, "end_time": 156.7,
 *   "hook_text": "...", "moment_type": "punchline",
 *   "reasons": ["..."], "explanation": "...",
 *   "suggested_title": "...", "suggested_caption": "...",
 *   "suggested_hashtags": ["#a", "#b"],
 *   "overall_score": 85, "engagement_score": 85, "hook_score": 90,
 *   "story_score": 70, "emotional_score": 60, "information_score": 50,
 *   "viral_potential": 80
 * }, ...]
 * Score fields are optional (default 75, matching the OpenAI provider's default).
 */
class ImportPunchlineCandidates extends Command
{
    protected $signature = 'clips:import-punchlines
        {project : Project ID}
        {--file= : Path to a JSON file with the picked punchline candidates}
        {--min-duration=8 : Reject candidates shorter than this many seconds}';

    protected $description = 'Import manually-picked punchline moments (from reading a .srt outside the app) as clip candidates';

    public function handle(SrtTranscriptionBuilder $srtBuilder): int
    {
        $project = Project::with('videos')->find((int) $this->argument('project'));
        if (! $project) {
            $this->error('Project not found.');

            return self::FAILURE;
        }

        $video = $project->videos()->latest()->first();
        if (! $video || $video->status !== 'ready') {
            $this->error('This project has no downloaded-and-ready video yet.');

            return self::FAILURE;
        }

        $filePath = $this->option('file');
        if (! $filePath || ! is_file($filePath)) {
            $this->error('Pass --file=path/to/candidates.json with the picked punchline moments.');

            return self::FAILURE;
        }

        $picks = json_decode((string) file_get_contents($filePath), true);
        if (! is_array($picks) || empty($picks)) {
            $this->error('That file has no candidates in it (expected a JSON array).');

            return self::FAILURE;
        }

        $disk = Storage::disk('media');

        if (! $video->transcript) {
            $srtPath = $video->metadata['captions_srt_path'] ?? null;
            if (! $srtPath || ! $disk->exists($srtPath)) {
                $this->error('No transcript exists yet and no source .srt was found on disk — nothing to build captions from.');

                return self::FAILURE;
            }

            $result = $srtBuilder->build($disk->get($srtPath), $video->metadata['captions_language'] ?? 'unknown');
            Transcript::updateOrCreate(['video_id' => $video->id], $result->toArray());
            $this->info("Built transcript from {$srtPath}.");
        }

        $minDuration = (float) $this->option('min-duration');
        $duration = (float) ($video->duration_seconds ?? PHP_INT_MAX);

        $created = 0;
        $skipped = 0;

        ClipCandidate::where('video_id', $video->id)->delete();

        foreach ($picks as $pick) {
            $start = (float) ($pick['start_time'] ?? -1);
            $end = (float) ($pick['end_time'] ?? -1);

            if ($start < 0 || $end <= $start || ($end - $start) < $minDuration || $end > $duration + 1) {
                $skipped++;
                continue;
            }

            $data = new ClipCandidateData(
                startTime: round($start, 2),
                endTime: round($end, 2),
                overallScore: $this->clampScore($pick['overall_score'] ?? 75),
                engagementScore: $this->clampScore($pick['engagement_score'] ?? 75),
                hookScore: $this->clampScore($pick['hook_score'] ?? 75),
                storyScore: $this->clampScore($pick['story_score'] ?? 75),
                emotionalScore: $this->clampScore($pick['emotional_score'] ?? 75),
                informationScore: $this->clampScore($pick['information_score'] ?? 75),
                viralPotential: $this->clampScore($pick['viral_potential'] ?? 75),
                hookText: (string) ($pick['hook_text'] ?? ''),
                momentType: (string) ($pick['moment_type'] ?? 'punchline'),
                reasons: array_values(array_map('strval', $pick['reasons'] ?? [])),
                explanation: (string) ($pick['explanation'] ?? ''),
                suggestedTitle: (string) ($pick['suggested_title'] ?? $video->title ?? 'Untitled clip'),
                suggestedCaption: (string) ($pick['suggested_caption'] ?? ''),
                hashtags: array_values(array_map('strval', $pick['suggested_hashtags'] ?? [])),
            );

            ClipCandidate::create($data->toModelAttributes($project->id, $video->id));
            $created++;
        }

        $project->update(['status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);

        $this->info("Imported {$created} clip candidate(s) for project #{$project->id}." . ($skipped ? " Skipped {$skipped} invalid entr" . ($skipped === 1 ? 'y' : 'ies') . '.' : ''));

        return self::SUCCESS;
    }

    private function clampScore(mixed $value): int
    {
        return max(1, min(100, (int) $value));
    }
}
