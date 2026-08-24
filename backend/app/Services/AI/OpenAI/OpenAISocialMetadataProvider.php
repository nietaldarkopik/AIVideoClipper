<?php

namespace App\Services\AI\OpenAI;

use App\Models\Clip;
use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Concerns\ParsesJsonResponses;
use App\Services\AI\Contracts\SocialMetadataProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAISocialMetadataProvider implements SocialMetadataProvider
{
    use LogsAiRequests;
    use ParsesJsonResponses;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gpt-4o-mini',
    ) {
    }

    public function generateMetadata(Clip $clip, array $platforms): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY is not set — required for AI_SOCIAL_METADATA_PROVIDER=openai.');
        }

        $context = sprintf(
            "Clip title: %s\nHook / caption: %s\nExisting hashtags: %s",
            $clip->title ?: '(none)',
            $clip->clipCandidate?->hook_text ?? $clip->caption ?? '(none)',
            implode(' ', $clip->hashtags ?? [])
        );

        $platformList = implode(', ', $platforms);
        $systemPrompt = $this->systemPrompt($platformList);
        $span = $this->aiLogger()->start('social_metadata', 'openai', $this->model, $systemPrompt . "\n\n" . $context);

        $response = Http::withToken($this->apiKey)
            ->timeout(60)
            ->retry(2, 1500)
            ->withOptions(['version' => 1.1])
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.6,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $context],
                ],
            ]);

        if ($response->failed()) {
            $span->failure($response->body());

            throw new RuntimeException('OpenAI social metadata generation failed: ' . $response->body());
        }

        $raw = (string) $response->json('choices.0.message.content');
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
