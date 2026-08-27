<?php

namespace App\Services\AI\Gemini;

use App\Models\Clip;
use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Concerns\ParsesJsonResponses;
use App\Services\AI\Contracts\ReactionScriptProvider;
use App\Services\AI\DTOs\ReactionScriptResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real reaction-script generation via the Google AI Studio (Gemini) API — same
 * job as OpenAIReactionScriptProvider/OllamaReactionScriptProvider, just backed
 * by Gemini's structured-output mode (responseSchema), same approach as
 * GeminiContentAnalysisProvider.
 */
class GeminiReactionScriptProvider implements ReactionScriptProvider
{
    use LogsAiRequests;
    use ParsesJsonResponses;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gemini-2.5-flash',
        private readonly int $timeoutSeconds = 60,
    ) {
    }

    public function generateReactionScript(Clip $clip): ReactionScriptResult
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY is not set — required for AI_REACTION_SCRIPT_PROVIDER=gemini.');
        }

        $context = $this->buildContext($clip);
        $systemPrompt = $this->systemPrompt();
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";
        $span = $this->aiLogger()->start('reaction_script', 'gemini', $this->model, $systemPrompt . "\n\n" . $context);

        $response = Http::timeout($this->timeoutSeconds)
            ->retry(2, 1500)
            ->withOptions(['version' => 1.1])
            ->withHeaders(['x-goog-api-key' => $this->apiKey])
            ->post($url, [
                'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $context]]],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $this->responseSchema(),
                    'temperature' => 0.8,
                ],
            ]);

        if ($response->failed()) {
            $span->failure($response->body());

            throw new RuntimeException('Gemini reaction script generation failed: ' . $response->body());
        }

        $raw = $response->json('candidates.0.content.parts.0.text');
        $span->success((string) $raw);
        $parsed = $this->extractJsonObject((string) $raw) ?? [];

        $text = trim((string) ($parsed['text'] ?? ''));
        $tone = ($parsed['tone'] ?? 'positive') === 'satire' ? 'satire' : 'positive';

        if ($text === '') {
            throw new RuntimeException('Gemini reaction script generation returned empty text.');
        }

        return new ReactionScriptResult($text, $tone);
    }

    private function responseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'text' => ['type' => 'STRING'],
                'tone' => ['type' => 'STRING', 'enum' => ['positive', 'satire']],
            ],
            'required' => ['text', 'tone'],
        ];
    }

    private function buildContext(Clip $clip): string
    {
        $candidate = $clip->clipCandidate;
        $transcriptExcerpt = $this->transcriptExcerpt($clip);

        return sprintf(
            "Clip title: %s\nHook: %s\nExplanation: %s\nMoment type: %s\nOverall score (1-100): %s\nWhy it was picked: %s\nTranscript of this clip:\n%s",
            $clip->title ?: '(none)',
            $candidate?->hook_text ?? $clip->caption ?? '(none)',
            $candidate?->explanation ?? '(none)',
            $candidate?->moment_type ?? '(unknown)',
            $candidate?->overall_score ?? '(unknown)',
            implode('; ', $candidate?->reasons ?? []) ?: '(none)',
            $transcriptExcerpt !== '' ? $transcriptExcerpt : '(no transcript available)'
        );
    }

    private function transcriptExcerpt(Clip $clip): string
    {
        $segments = $clip->video?->transcript?->segments ?? [];
        if (empty($segments)) {
            return '';
        }

        $lines = [];
        foreach ($segments as $segment) {
            $end = (float) ($segment['end'] ?? 0);
            $start = (float) ($segment['start'] ?? 0);
            if ($end < (float) $clip->start_time || $start > (float) $clip->end_time) {
                continue;
            }
            $lines[] = trim((string) ($segment['text'] ?? ''));
        }

        return implode(' ', array_filter($lines));
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
You are a hot-take commentator writing a ONE-LINE reaction to a short video clip,
to be read aloud over a cover screen right before the clip plays.

Judge the clip's content honestly from the transcript and context given:
- If it's genuinely good, impressive, funny, or valuable: write a short, punchy,
  HYPE/positive reaction (like a genuinely excited commentator).
- If it's bad, cringe, incompetent, or otherwise deserves mockery: write a short,
  SATIRICAL/sindiran reaction — witty and cutting, not just negative.

Rules: max ~25 words, one sentence, no hashtags, no emoji, written in the exact
same language as the transcript (never translate to English unless the transcript
itself is in English).
PROMPT;
    }
}
