<?php

namespace App\Services\AI\Ollama;

use App\Models\Clip;
use App\Services\AI\Contracts\SocialMetadataProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaSocialMetadataProvider implements SocialMetadataProvider
{
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

        $response = Http::timeout($this->timeoutSeconds)
            ->post(rtrim($this->baseUrl, '/') . '/v1/chat/completions', [
                'model' => $this->model,
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.6,
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt($platformList)],
                    ['role' => 'user', 'content' => $context],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Ollama social metadata generation failed ({$response->status()}): {$response->body()}. " .
                "Is Ollama running at {$this->baseUrl} with model \"{$this->model}\" pulled?"
            );
        }

        $parsed = json_decode((string) $response->json('choices.0.message.content'), true) ?? [];
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
