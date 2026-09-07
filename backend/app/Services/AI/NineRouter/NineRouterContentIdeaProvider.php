<?php

namespace App\Services\AI\NineRouter;

use App\Services\AI\Concerns\GeneratesContentIdeas;
use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Contracts\ContentIdeaProvider;
use App\Services\Research\Engine\ContentIdeaPromptBuilder;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Content ideas via a self-hosted 9Router instance — the same OpenAI-compatible
 * /chat/completions call as the other NineRouter providers in this app.
 */
class NineRouterContentIdeaProvider implements ContentIdeaProvider
{
    use GeneratesContentIdeas;
    use LogsAiRequests;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        // No universal default — see NineRouterContentAnalysisProvider's docblock.
        private readonly string $model = '',
    ) {}

    public function generateIdeas(array $context, int $count): array
    {
        if ($this->model === '') {
            throw new RuntimeException(
                'NINE_ROUTER_MODEL is not set. Check GET '.rtrim($this->baseUrl, '/').
                '/models for the model ids your 9Router instance actually has credentials for.'
            );
        }

        $systemPrompt = ContentIdeaPromptBuilder::systemPrompt($count, (string) ($context['language'] ?? 'id'));
        $userPrompt = $this->contextPayload($context);

        $span = $this->aiLogger()->start('content_ideas', 'nine_router', $this->model, $systemPrompt."\n\n".$userPrompt);

        $request = Http::timeout(180)->retry(2, 1500)->withOptions(['version' => 1.1]);

        if ($this->apiKey) {
            $request = $request->withToken($this->apiKey);
        }

        $response = $request->post(rtrim($this->baseUrl, '/').'/chat/completions', [
            'model' => $this->model,
            'stream' => false,
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.7,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ]);

        if ($response->failed()) {
            $span->failure($response->body());

            throw new RuntimeException('9Router content idea generation failed: '.$response->body());
        }

        $raw = (string) $response->json('choices.0.message.content');
        $span->success($raw);

        return $this->parseIdeas($raw, $count);
    }
}
