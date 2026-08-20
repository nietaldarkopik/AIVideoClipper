<?php

namespace App\Services\AI\Gemini;

use App\Models\Transcript;
use App\Services\AI\Contracts\ContentAnalysisProvider;
use App\Services\AI\DTOs\ClipCandidateData;
use App\Services\AI\DTOs\SceneMarker;
use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Real moment-detection via the Google AI Studio (Gemini) API. Same job as
 * OpenAIContentAnalysisProvider/ClaudeContentAnalysisProvider — read the
 * transcript, pick punchline moments — just backed by Gemini instead.
 *
 * Uses Gemini's structured-output mode (responseMimeType: application/json +
 * responseSchema) rather than prompting for raw JSON: the API enforces the shape
 * server-side, so there's no "the model wrapped it in prose" failure mode to
 * guard against.
 */
class GeminiContentAnalysisProvider implements ContentAnalysisProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gemini-2.5-flash',
        private readonly int $timeoutSeconds = 180,
    ) {
    }

    /**
     * Real shot-boundary detection would need a full frame-diff decode pass; kept as
     * a lightweight, evenly spaced heuristic used only as auxiliary prompt context,
     * same simplification the other real providers make.
     */
    public function detectScenes(string $videoPath, float $durationSeconds): array
    {
        $scenes = [];
        for ($t = 8.0; $t < $durationSeconds; $t += 10.0) {
            $scenes[] = new SceneMarker(round($t, 2));
        }

        return $scenes;
    }

    public function analyzeMoments(Transcript $transcript, array $scenes, float $durationSeconds, ?Closure $shouldAbort = null): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY is not set — required for AI_ANALYSIS_PROVIDER=gemini.');
        }

        $segments = $transcript->segments ?? [];
        if (empty($segments)) {
            return [];
        }

        $transcriptText = implode("\n", array_map(
            fn ($s) => sprintf('[%s-%s] %s', $this->fmt($s['start']), $this->fmt($s['end']), $s['text']),
            $segments
        ));

        $maxCandidates = min(8, max(3, (int) round($durationSeconds / 180)));

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";

        $response = Http::timeout($this->timeoutSeconds)
            ->retry(2, 2000)
            ->withOptions(['version' => 1.1])
            ->withHeaders(['x-goog-api-key' => $this->apiKey])
            ->post($url, [
                'system_instruction' => ['parts' => [['text' => $this->systemPrompt($maxCandidates)]]],
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $this->userPrompt($transcriptText, $durationSeconds, $transcript->language)]]],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $this->responseSchema(),
                    'temperature' => 0.4,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Gemini analysis failed: ' . $response->body());
        }

        $raw = $response->json('candidates.0.content.parts.0.text');
        $parsed = json_decode((string) $raw, true);
        $candidates = $parsed['candidates'] ?? null;

        if (! is_array($candidates)) {
            Log::warning('Gemini analysis returned unexpected shape', ['raw' => $raw]);

            return [];
        }

        $results = [];
        foreach ($candidates as $c) {
            $start = (float) ($c['start_time'] ?? 0);
            $end = (float) ($c['end_time'] ?? 0);
            if ($end <= $start || $start < 0 || $start > $durationSeconds + 1 || ($end - $start) < 8) {
                continue;
            }

            $results[] = new ClipCandidateData(
                startTime: round($start, 2),
                endTime: round(min($end, $durationSeconds), 2),
                overallScore: $this->clampScore($c['overall_score'] ?? 75),
                engagementScore: $this->clampScore($c['engagement_score'] ?? 75),
                hookScore: $this->clampScore($c['hook_score'] ?? 75),
                storyScore: $this->clampScore($c['story_score'] ?? 75),
                emotionalScore: $this->clampScore($c['emotional_score'] ?? 75),
                informationScore: $this->clampScore($c['information_score'] ?? 75),
                viralPotential: $this->clampScore($c['viral_potential'] ?? 75),
                hookText: (string) ($c['hook_text'] ?? ''),
                momentType: (string) ($c['moment_type'] ?? 'hook'),
                reasons: array_values(array_filter((array) ($c['reasons'] ?? []), 'is_string')),
                explanation: (string) ($c['explanation'] ?? ''),
                suggestedTitle: (string) ($c['suggested_title'] ?? ''),
                suggestedCaption: (string) ($c['suggested_caption'] ?? ''),
                hashtags: array_values(array_filter((array) ($c['hashtags'] ?? []), 'is_string')),
            );
        }

        usort($results, fn ($a, $b) => $b->overallScore <=> $a->overallScore);

        return $results;
    }

    private function responseSchema(): array
    {
        $score = ['type' => 'INTEGER'];

        return [
            'type' => 'OBJECT',
            'properties' => [
                'candidates' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'start_time' => ['type' => 'NUMBER'],
                            'end_time' => ['type' => 'NUMBER'],
                            'overall_score' => $score,
                            'engagement_score' => $score,
                            'hook_score' => $score,
                            'story_score' => $score,
                            'emotional_score' => $score,
                            'information_score' => $score,
                            'viral_potential' => $score,
                            'hook_text' => ['type' => 'STRING'],
                            'moment_type' => [
                                'type' => 'STRING',
                                'enum' => ['hook', 'question', 'emotional', 'funny', 'controversial', 'educational', 'story_peak', 'conclusion', 'punchline', 'reveal'],
                            ],
                            'reasons' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                            'explanation' => ['type' => 'STRING'],
                            'suggested_title' => ['type' => 'STRING'],
                            'suggested_caption' => ['type' => 'STRING'],
                            'hashtags' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                        ],
                        'required' => ['start_time', 'end_time', 'hook_text', 'suggested_title'],
                    ],
                ],
            ],
            'required' => ['candidates'],
        ];
    }

    private function systemPrompt(int $maxCandidates): string
    {
        return <<<PROMPT
You are an expert short-form video producer. Given a timestamped transcript of a
long video, find the {$maxCandidates} best self-contained "punchline" moments to cut
into short vertical clips (like TikTok/Reels/Shorts) — cold-open hooks, twist
reveals, and payoff lines that work with little to no extra context.

Return up to {$maxCandidates} candidates. Each needs: start_time/end_time (seconds;
15-90s span after start_time unless the moment genuinely needs longer), the six
1-100 scores plus viral_potential, hook_text (the exact or near-exact opening line,
in the transcript's own language), moment_type, 2-4 short reasons, a one-sentence
explanation, a short punchy suggested_title, a 1-2 sentence suggested_caption, and
4-6 relevant hashtags.

Critical: hook_text, suggested_title, suggested_caption, and reasons/explanation must be
written in the SAME LANGUAGE as the transcript — never translate to English unless the
transcript itself is in English. Prefer moments with a strong opening line, a clear
self-contained idea, and an emotional or informational payoff. Only use start_time/end_time
values that fall within the transcript's timestamp range.
PROMPT;
    }

    private function userPrompt(string $transcriptText, float $durationSeconds, string $language): string
    {
        return "Video duration: {$durationSeconds} seconds. Transcript language: {$language}.\n\nTranscript:\n{$transcriptText}";
    }

    private function clampScore(mixed $value): int
    {
        return max(1, min(100, (int) round((float) $value)));
    }

    private function fmt(float $seconds): string
    {
        return sprintf('%d:%02d', intdiv((int) $seconds, 60), ((int) $seconds) % 60);
    }
}
