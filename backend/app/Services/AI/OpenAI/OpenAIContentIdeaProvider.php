<?php

namespace App\Services\AI\OpenAI;

use App\Services\AI\Concerns\GeneratesContentIdeas;
use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Contracts\ContentIdeaProvider;
use App\Services\Research\Engine\ContentIdeaPromptBuilder;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIContentIdeaProvider implements ContentIdeaProvider
{
    use GeneratesContentIdeas;
    use LogsAiRequests;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gpt-4o-mini',
    ) {}

    public function generateIdeas(array $context, int $count): array
    {
        $systemPrompt = ContentIdeaPromptBuilder::systemPrompt($count, (string) ($context['language'] ?? 'id'));
        $userPrompt = $this->contextPayload($context);

        $span = $this->aiLogger()->start('content_ideas', 'openai', $this->model, $systemPrompt."\n\n".$userPrompt);

        $response = Http::withToken($this->apiKey)
            // Longer than the 60s used elsewhere: this prompt carries a full run's
            // research evidence and asks for several structured ideas at once.
            ->timeout(120)
            ->retry(2, 1500)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'response_format' => ['type' => 'json_object'],
                // Low but non-zero: ideas should vary run to run, while staying anchored
                // to the supplied research.
                'temperature' => 0.7,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if ($response->failed()) {
            $span->failure($response->body());

            throw new RuntimeException('OpenAI content idea generation failed: '.$response->body());
        }

        $raw = (string) $response->json('choices.0.message.content');
        $span->success($raw);

        return $this->parseIdeas($raw, $count);
    }
}
