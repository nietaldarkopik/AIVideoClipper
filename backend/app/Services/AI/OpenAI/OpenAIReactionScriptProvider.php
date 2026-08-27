<?php

namespace App\Services\AI\OpenAI;

use App\Models\Clip;
use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Concerns\ParsesJsonResponses;
use App\Services\AI\Contracts\ReactionScriptProvider;
use App\Services\AI\DTOs\ReactionScriptResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIReactionScriptProvider implements ReactionScriptProvider
{
    use LogsAiRequests;
    use ParsesJsonResponses;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gpt-4o-mini',
    ) {
    }

    public function generateReactionScript(Clip $clip): ReactionScriptResult
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY is not set — required for AI_REACTION_SCRIPT_PROVIDER=openai.');
        }

        $context = $this->buildContext($clip);
        $systemPrompt = $this->systemPrompt();
        $span = $this->aiLogger()->start('reaction_script', 'openai', $this->model, $systemPrompt . "\n\n" . $context);

        $response = Http::withToken($this->apiKey)
            ->timeout(60)
            ->retry(2, 1500)
            ->withOptions(['version' => 1.1])
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.8,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $context],
                ],
            ]);

        if ($response->failed()) {
            $span->failure($response->body());

            throw new RuntimeException('OpenAI reaction script generation failed: ' . $response->body());
        }

        $raw = (string) $response->json('choices.0.message.content');
        $span->success($raw);
        $parsed = $this->extractJsonObject($raw) ?? [];

        $text = trim((string) ($parsed['text'] ?? ''));
        $tone = ($parsed['tone'] ?? 'positive') === 'satire' ? 'satire' : 'positive';

        if ($text === '') {
            throw new RuntimeException('OpenAI reaction script generation returned empty text.');
        }

        return new ReactionScriptResult($text, $tone);
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
itself is in English). Respond with ONLY a JSON object:
{"text": "...", "tone": "positive"|"satire"}
PROMPT;
    }
}
