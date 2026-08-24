<?php

namespace App\Services\AI\OpenAI;

use App\Models\Transcript;
use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Concerns\ParsesJsonResponses;
use App\Services\AI\Contracts\ContentAnalysisProvider;
use App\Services\AI\DTOs\ClipCandidateData;
use App\Services\AI\DTOs\SceneMarker;
use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Real moment-detection via an OpenAI chat model reading the actual transcript.
 * Unlike the mock provider, hooks/titles/captions/reasons come out in whatever
 * language the transcript is in (matches the source video, e.g. Indonesian in,
 * Indonesian out) instead of canned English text.
 */
class OpenAIContentAnalysisProvider implements ContentAnalysisProvider
{
    use LogsAiRequests;
    use ParsesJsonResponses;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gpt-4o-mini',
    ) {
    }

    /**
     * Real shot-boundary detection would need a full frame-diff decode pass; that's
     * a heavier feature than this pass covers, so scenes stay a lightweight, evenly
     * spaced heuristic used only as auxiliary context for the LLM prompt below.
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
            throw new RuntimeException('OPENAI_API_KEY is not set — required for AI_ANALYSIS_PROVIDER=openai.');
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

        $systemPrompt = $this->systemPrompt($maxCandidates);
        $userPrompt = $this->userPrompt($transcriptText, $durationSeconds, $transcript->language);
        $span = $this->aiLogger()->start('content_analysis', 'openai', $this->model, $systemPrompt . "\n\n" . $userPrompt);

        $response = Http::withToken($this->apiKey)
            ->timeout(180)
            ->retry(2, 2000)
            // HTTP/2 hangs indefinitely on some networks (local TLS-inspecting
            // security software, etc.) with zero error — force HTTP/1.1.
            ->withOptions(['version' => 1.1])
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.4,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if ($response->failed()) {
            $span->failure($response->body());

            throw new RuntimeException('OpenAI analysis failed: ' . $response->body());
        }

        $raw = $response->json('choices.0.message.content');
        $span->success((string) $raw);
        $parsed = $this->extractJsonObject((string) $raw);
        $candidates = $parsed['candidates'] ?? [];

        if (! is_array($candidates)) {
            Log::warning('OpenAI analysis returned unexpected shape', ['raw' => $raw]);

            return [];
        }

        $results = [];
        foreach ($candidates as $c) {
            $start = (float) ($c['start_time'] ?? 0);
            $end = (float) ($c['end_time'] ?? 0);
            if ($end <= $start || $start < 0 || $end > $durationSeconds + 1 || ($end - $start) < 8) {
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

    private function systemPrompt(int $maxCandidates): string
    {
        return <<<PROMPT
You are an expert short-form video producer. Given a timestamped transcript of a
long video, find the {$maxCandidates} best self-contained moments to cut into
short vertical clips (like TikTok/Reels/Shorts).

Respond with ONLY a JSON object: {"candidates": [...]}. Each candidate object must have:
start_time (number, seconds), end_time (number, seconds, 15-90 seconds after start_time
unless the moment genuinely needs longer), overall_score (1-100), engagement_score (1-100),
hook_score (1-100), story_score (1-100), emotional_score (1-100), information_score (1-100),
viral_potential (1-100), hook_text (the exact or near-exact opening line, in the transcript's
own language), moment_type (one of: hook, question, emotional, funny, controversial,
educational, story_peak, conclusion), reasons (array of 2-4 short strings explaining why this
clip works), explanation (one sentence summary), suggested_title (short, punchy, in the
transcript's own language), suggested_caption (1-2 sentences, in the transcript's own
language), hashtags (array of 4-6 relevant hashtag strings, no spaces).

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
