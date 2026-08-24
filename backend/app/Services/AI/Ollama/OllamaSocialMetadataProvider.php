<?php

namespace App\Services\AI\Ollama;

use App\Models\Clip;
use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Concerns\ParsesJsonResponses;
use App\Services\AI\Contracts\SocialMetadataProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaSocialMetadataProvider implements SocialMetadataProvider
{
    use LogsAiRequests;
    use ParsesJsonResponses;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds = 60,
    ) {
    }

    public function generateMetadata(Clip $clip, array $platforms): array
    {
        $context = sprintf(
            "Clip title: %s\nHook / caption: %s\nExisting hashtags: %s",
            $clip->title ?: '(none)',
            $clip->clipCandidate?->hook_text ?? $clip->caption ?? '(none)',
            implode(' ', $clip->hashtags ?? [])
        );

        $platformList = implode(', ', $platforms);
        $systemPrompt = $this->systemPrompt($platformList);
        $span = $this->aiLogger()->start('social_metadata', 'ollama', $this->model, $systemPrompt . "\n\n" . $context);

        $response = Http::timeout($this->timeoutSeconds)
            ->post(rtrim($this->baseUrl, '/') . '/api/chat', [
                'model' => $this->model,
                'stream' => false,
                'format' => 'json',
                'options' => ['temperature' => 0.6],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $context],
                ],
            ]);

        if ($response->failed()) {
            $message = "Ollama social metadata generation failed ({$response->status()}): {$response->body()}. " .
                "Is Ollama running at {$this->baseUrl} with model \"{$this->model}\" pulled?";
            $span->failure($message);

            throw new RuntimeException($message);
        }

        $raw = $this->extractMessageContent($response->body());
        $span->success($raw);
        $parsed = $this->extractJsonObject($raw) ?? [];
        $result = [];

        foreach ($platforms as $platform) {
            $entry = $parsed[$platform] ?? [];
            $result[$platform] = [
                'title' => $entry['title'] ?? null,
                'caption' => $entry['caption'] ?? null,
                'description' => $entry['description'] ?? null,
                'hashtags' => array_values(array_filter((array) ($entry['hashtags'] ?? []), 'is_string')),
                'cta' => $entry['cta'] ?? null,
                'first_comment' => $entry['first_comment'] ?? null,
            ];
        }

        return $result;
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

    private function systemPrompt(string $platformList): string
    {
        return <<<PROMPT
You write short-form social media captions. Given a video clip's title and hook,
write platform-tailored metadata for each of: {$platformList}.

Respond with ONLY a JSON object keyed by platform name, e.g.
{"tiktok": {"caption": "...", "hashtags": ["#..."], "cta": "...", "first_comment": "..."},
 "youtube": {"title": "...", "description": "...", "hashtags": [...]}, ...}.

Match each platform's norms: TikTok/Instagram/X get punchy short captions + hashtags;
YouTube gets a title + longer description; LinkedIn gets a more professional tone.
Write in the same language as the input clip title/hook — never translate to English
unless the input itself is in English.
PROMPT;
    }
}
