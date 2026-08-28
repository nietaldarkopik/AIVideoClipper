<?php

namespace App\Services\AI\Ollama;

use App\Models\Clip;
use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Concerns\ParsesJsonResponses;
use App\Services\AI\Contracts\ReactionScriptProvider;
use App\Services\AI\DTOs\ReactionScriptResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaReactionScriptProvider implements ReactionScriptProvider
{
    use LogsAiRequests;
    use ParsesJsonResponses;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds = 60,
    ) {
    }

    public function generateReactionScript(Clip $clip, ?string $referenceContent = null): ReactionScriptResult
    {
        $context = $this->buildContext($clip, $referenceContent);
        $systemPrompt = $this->systemPrompt();
        $span = $this->aiLogger()->start('reaction_script', 'ollama', $this->model, $systemPrompt . "\n\n" . $context);

        $response = Http::timeout($this->timeoutSeconds)
            ->post(rtrim($this->baseUrl, '/') . '/api/chat', [
                'model' => $this->model,
                'stream' => false,
                'format' => 'json',
                'options' => ['temperature' => 0.8],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $context],
                ],
            ]);

        if ($response->failed()) {
            $message = "Ollama reaction script generation failed ({$response->status()}): {$response->body()}. " .
                "Is Ollama running at {$this->baseUrl} with model \"{$this->model}\" pulled?";
            $span->failure($message);

            throw new RuntimeException($message);
        }

        $raw = $this->extractMessageContent($response->body());
        $span->success($raw);
        $parsed = $this->extractJsonObject($raw) ?? [];

        $text = trim((string) ($parsed['text'] ?? ''));
        $tone = ($parsed['tone'] ?? 'positive') === 'satire' ? 'satire' : 'positive';

        if ($text === '') {
            throw new RuntimeException('Ollama reaction script generation returned empty text.');
        }

        return new ReactionScriptResult($text, $tone);
    }

    private function buildContext(Clip $clip, ?string $referenceContent = null): string
    {
        $candidate = $clip->clipCandidate;
        $transcriptExcerpt = $this->transcriptExcerpt($clip);

        $context = sprintf(
            "Clip title: %s\nHook: %s\nExplanation: %s\nMoment type: %s\nOverall score (1-100): %s\nWhy it was picked: %s\nTranscript of this clip:\n%s",
            $clip->title ?: '(none)',
            $candidate?->hook_text ?? $clip->caption ?? '(none)',
            $candidate?->explanation ?? '(none)',
            $candidate?->moment_type ?? '(unknown)',
            $candidate?->overall_score ?? '(unknown)',
            implode('; ', $candidate?->reasons ?? []) ?: '(none)',
            $transcriptExcerpt !== '' ? $transcriptExcerpt : '(no transcript available)'
        );

        if (filled($referenceContent)) {
            $context .= "\n\nReference source (from a URL the user supplied — use it for extra context/facts):\n{$referenceContent}";
        }

        return $context;
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

    /**
     * We always send stream:false, but some proxies/servers stream regardless —
     * in that case the body is newline-delimited JSON chunks (Ollama's native
     * streaming format) rather than one JSON object. Handle both.
     */
    private function extractMessageContent(string $body): string
    {
        $single = json_decode($body, true);
        if (is_array($single) && isset($single['message']['content'])) {
            return (string) $single['message']['content'];
        }

        $content = '';
        foreach (explode("\n", trim($body)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $chunk = json_decode($line, true);
            $content .= (string) ($chunk['message']['content'] ?? '');
        }

        return $content;
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
