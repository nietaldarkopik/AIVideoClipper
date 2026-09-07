<?php

namespace App\Services\AI\Claude;

use App\Models\Transcript;
use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Contracts\ContentAnalysisProvider;
use App\Services\AI\DTOs\ClipCandidateData;
use App\Services\AI\DTOs\SceneMarker;
use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Real moment-detection via the Anthropic Messages API — the automated equivalent
 * of the manual "cowork" workflow (reading a video's transcript in a separate
 * Claude conversation to pick punchline moments by hand, then importing the picks
 * via `clips:import-punchlines`). This plugs the same analysis directly into the
 * normal pipeline (AnalyzeVideoJob, and therefore the batch autobot too, since it
 * runs AnalyzeVideoJob's handle() in-process) — no manual file-reading step needed.
 *
 * Uses tool use (forced tool_choice) instead of prompting for raw JSON: Claude has
 * no dedicated "JSON mode" the way OpenAI does, and a forced tool call is the
 * reliable way to get back a value that's already valid, schema-shaped JSON.
 */
class ClaudeContentAnalysisProvider implements ContentAnalysisProvider
{
    use LogsAiRequests;

    private const TOOL_NAME = 'submit_clip_candidates';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'claude-sonnet-5',
        private readonly int $timeoutSeconds = 180,
    ) {}

    /**
     * Real shot-boundary detection would need a full frame-diff decode pass; kept as
     * a lightweight, evenly spaced heuristic used only as auxiliary prompt context,
     * same simplification OpenAIContentAnalysisProvider makes.
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
            throw new RuntimeException('ANTHROPIC_API_KEY is not set — required for AI_ANALYSIS_PROVIDER=claude.');
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
        $span = $this->aiLogger()->start('content_analysis', 'claude', $this->model, $systemPrompt."\n\n".$userPrompt);

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout($this->timeoutSeconds)
            ->retry(2, 2000)
            ->withOptions(['version' => 1.1])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 8192,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'tools' => [$this->toolDefinition()],
                'tool_choice' => ['type' => 'tool', 'name' => self::TOOL_NAME],
            ]);

        if ($response->failed()) {
            $span->failure($response->body());

            throw new RuntimeException('Claude analysis failed: '.$response->body());
        }

        $span->success(json_encode($response->json('content')));
        $toolUse = collect($response->json('content'))->firstWhere('type', 'tool_use');
        $candidates = $toolUse['input']['candidates'] ?? null;

        if (! is_array($candidates)) {
            Log::warning('Claude analysis returned no tool_use candidates', ['response' => $response->json()]);

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
                coverTitles: ClipCandidateData::normalizeCoverStrings($c['cover_titles'] ?? [], 42),
                coverSubtitles: ClipCandidateData::normalizeCoverStrings($c['cover_subtitles'] ?? [], 18),
            );
        }

        usort($results, fn ($a, $b) => $b->overallScore <=> $a->overallScore);

        return $results;
    }

    private function toolDefinition(): array
    {
        return [
            'name' => self::TOOL_NAME,
            'description' => 'Submit the best short-form clip candidates (punchline moments) found in the transcript.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'candidates' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'start_time' => ['type' => 'number'],
                                'end_time' => ['type' => 'number'],
                                'overall_score' => ['type' => 'integer'],
                                'engagement_score' => ['type' => 'integer'],
                                'hook_score' => ['type' => 'integer'],
                                'story_score' => ['type' => 'integer'],
                                'emotional_score' => ['type' => 'integer'],
                                'information_score' => ['type' => 'integer'],
                                'viral_potential' => ['type' => 'integer'],
                                'hook_text' => ['type' => 'string'],
                                'moment_type' => [
                                    'type' => 'string',
                                    'enum' => ['hook', 'question', 'emotional', 'funny', 'controversial', 'educational', 'story_peak', 'conclusion', 'punchline', 'reveal'],
                                ],
                                'reasons' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'explanation' => ['type' => 'string'],
                                'suggested_title' => ['type' => 'string'],
                                'suggested_caption' => ['type' => 'string'],
                                'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'cover_titles' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'cover_subtitles' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                            'required' => ['start_time', 'end_time', 'hook_text', 'suggested_title'],
                        ],
                    ],
                ],
                'required' => ['candidates'],
            ],
        ];
    }

    private function systemPrompt(int $maxCandidates): string
    {
        $coverInstructions = ClipCandidateData::coverPromptInstructions();

        return <<<PROMPT
You are an expert short-form video producer. Given a timestamped transcript of a
long video, find the {$maxCandidates} best self-contained "punchline" moments to cut
into short vertical clips (like TikTok/Reels/Shorts) — cold-open hooks, twist
reveals, and payoff lines that work with little to no extra context.

Call the submit_clip_candidates tool with up to {$maxCandidates} picks. Each
candidate needs: start_time/end_time (seconds; 15-90s span after start_time unless the
moment genuinely needs longer), the six 1-100 scores plus viral_potential, hook_text
(the exact or near-exact opening line, in the transcript's own language), moment_type,
2-4 short reasons, a one-sentence explanation, a short punchy suggested_title, a
1-2 sentence suggested_caption, and 4-6 relevant hashtags.

{$coverInstructions}

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
