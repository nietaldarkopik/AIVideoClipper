<?php

namespace App\Services\AI\DTOs;

class ClipCandidateData
{
    /**
     * @param  string[]  $reasons
     * @param  string[]  $hashtags
     * @param  string[]  $coverTitles  short, thumbnail-sized headline variants (a few words each)
     * @param  string[]  $coverSubtitles  even shorter kicker/subline variants (1-3 words each)
     */
    public function __construct(
        public readonly float $startTime,
        public readonly float $endTime,
        public readonly int $overallScore,
        public readonly int $engagementScore,
        public readonly int $hookScore,
        public readonly int $storyScore,
        public readonly int $emotionalScore,
        public readonly int $informationScore,
        public readonly int $viralPotential,
        public readonly string $hookText,
        public readonly string $momentType,
        public readonly array $reasons,
        public readonly string $explanation,
        public readonly string $suggestedTitle,
        public readonly string $suggestedCaption,
        public readonly array $hashtags,
        // Default [] so a provider that doesn't produce them yet (or an older
        // cached response) still constructs — CoverGeneratorService falls back
        // to hook_text/title exactly as before when these are empty.
        public readonly array $coverTitles = [],
        public readonly array $coverSubtitles = [],
    ) {}

    /**
     * The cover-text half of every ContentAnalysisProvider's system prompt,
     * kept here next to normalizeCoverStrings()'s limits so the instructions and
     * the validation can't drift apart across the six providers that share them.
     */
    public static function coverPromptInstructions(): string
    {
        return <<<'PROMPT'
Also produce, for each candidate, thumbnail text that is much shorter than the caption:
cover_titles (array of 2-3 alternative headlines for the video's cover image — each MAX 5
words / 42 characters, punchy and curiosity-driven, no ending period, no hashtags, no
quotes) and cover_subtitles (array of 2-3 tiny labels to sit above/below that headline —
each MAX 2 words / 18 characters, e.g. a category, a stake, or a teaser like "FAKTA BARU").
These are burned onto an image and must stay short enough to read at a glance — anything
longer than that is useless. Same language as the transcript.
PROMPT;
    }

    /**
     * Normalizes a raw model-supplied list of cover strings: trims, drops
     * empties, enforces a hard character ceiling (a thumbnail headline that
     * wraps to five lines stops being eye-catching — see
     * FFmpegService::renderCoverImage()'s wrapping) and caps how many variants
     * are kept.
     *
     * @return string[]
     */
    public static function normalizeCoverStrings(mixed $values, int $maxChars, int $maxItems = 3): array
    {
        return collect(is_array($values) ? $values : [])
            // Not ->filter('is_string'): Collection::filter passes (value, key),
            // and is_string() takes exactly one argument.
            ->filter(fn ($v) => is_string($v))
            ->map(fn (string $v) => trim(preg_replace('/\s+/u', ' ', $v)))
            ->filter(fn (string $v) => $v !== '' && mb_strlen($v) <= $maxChars)
            ->unique()
            ->take($maxItems)
            ->values()
            ->all();
    }

    public function toModelAttributes(int $projectId, int $videoId): array
    {
        return [
            'project_id' => $projectId,
            'video_id' => $videoId,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'duration' => round($this->endTime - $this->startTime, 2),
            'overall_score' => $this->overallScore,
            'engagement_score' => $this->engagementScore,
            'hook_score' => $this->hookScore,
            'story_score' => $this->storyScore,
            'emotional_score' => $this->emotionalScore,
            'information_score' => $this->informationScore,
            'viral_potential' => $this->viralPotential,
            'hook_text' => $this->hookText,
            'moment_type' => $this->momentType,
            'reasons' => $this->reasons,
            'explanation' => $this->explanation,
            'suggested_title' => $this->suggestedTitle,
            'suggested_caption' => $this->suggestedCaption,
            'suggested_hashtags' => $this->hashtags,
            'cover_titles' => $this->coverTitles,
            'cover_subtitles' => $this->coverSubtitles,
            'status' => 'pending',
        ];
    }
}
