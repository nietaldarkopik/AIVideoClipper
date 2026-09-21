<?php

namespace App\Services\AI\Gemini;

use App\Services\AI\Concerns\GeneratesContentIdeas;
use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Contracts\ContentIdeaProvider;
use App\Services\Research\Engine\ContentIdeaPromptBuilder;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Content ideas via Google AI Studio (Gemini), using responseSchema for hard
 * server-side structured output — the same approach as
 * GeminiContentAnalysisProvider and GeminiReactionScriptProvider.
 */
class GeminiContentIdeaProvider implements ContentIdeaProvider
{
    use GeneratesContentIdeas;
    use LogsAiRequests;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gemini-2.5-flash',
        private readonly int $timeoutSeconds = 180,
    ) {}

    public function generateIdeas(array $context, int $count): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not set — required for AI_CONTENT_IDEA_PROVIDER=gemini.');
        }

        $systemPrompt = ContentIdeaPromptBuilder::systemPrompt($count, (string) ($context['language'] ?? 'id'));
        $userPrompt = $this->contextPayload($context);
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";

        $span = $this->aiLogger()->start('content_ideas', 'gemini', $this->model, $systemPrompt."\n\n".$userPrompt);

        $response = Http::timeout($this->timeoutSeconds)
            ->retry(2, 1500)
            ->withOptions(['version' => 1.1])
            ->withHeaders(['x-goog-api-key' => $this->apiKey])
            ->post($url, [
                'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $this->responseSchema(),
                    'temperature' => 0.7,
                ],
            ]);

        if ($response->failed()) {
            $span->failure($response->body());

            throw new RuntimeException('Gemini content idea generation failed: '.$response->body());
        }

        $raw = (string) $response->json('candidates.0.content.parts.0.text');
        $span->success($raw);

        return $this->parseIdeas($raw, $count);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'ideas' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'topic_ref' => ['type' => 'STRING'],
                            'topic' => ['type' => 'STRING'],
                            'title' => ['type' => 'STRING'],
                            'alternative_titles' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                            'short_description' => ['type' => 'STRING'],
                            'content_angle' => ['type' => 'STRING'],
                            'why_this_topic' => ['type' => 'STRING'],
                            'target_audience' => ['type' => 'STRING'],
                            'keywords' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                            'suggested_content_type' => ['type' => 'STRING'],
                            'suggested_format' => ['type' => 'STRING'],
                            'originality_score' => ['type' => 'INTEGER'],
                        ],
                        // Only these are required: making every field required pushes the
                        // model to pad optional fields with filler rather than omit them.
                        'required' => ['topic_ref', 'topic', 'title'],
                    ],
                ],
            ],
            'required' => ['ideas'],
        ];
    }
}
