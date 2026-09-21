<?php

namespace App\Services\AI\NineRouter;

use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Contracts\EmbeddingProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real embeddings via a self-hosted 9Router instance's OpenAI-compatible
 * /embeddings endpoint — same Http call conventions as
 * NineRouterTextToSpeechProvider (forced HTTP/1.1, optional bearer token).
 */
class NineRouterEmbeddingProvider implements EmbeddingProvider
{
    use LogsAiRequests;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        // No universal default — see NineRouterContentAnalysisProvider's docblock.
        private readonly string $model = '',
    ) {
    }

    public function embed(string $text): array
    {
        if (empty($this->model)) {
            throw new RuntimeException(
                'NINE_ROUTER_EMBEDDING_MODEL is not set. Check GET ' . rtrim($this->baseUrl, '/') .
                '/models/embedding for the model ids your 9Router instance actually has credentials for.'
            );
        }

        $span = $this->aiLogger()->start('embedding', 'nine_router', $this->model, $text);

        $request = Http::timeout(60)
            ->retry(2, 1500)
            ->withOptions(['version' => 1.1]);

        if ($this->apiKey) {
            $request = $request->withToken($this->apiKey);
        }

        $response = $request->post(rtrim($this->baseUrl, '/') . '/embeddings', [
            'model' => $this->model,
            'input' => $text,
        ]);

        if ($response->failed()) {
            $span->failure($response->body());

            throw new RuntimeException('9Router embedding failed: ' . $response->body());
        }

        $vector = $response->json('data.0.embedding');
        $span->success('embedding: ' . count($vector ?? []) . ' dimensions');

        if (! is_array($vector)) {
            throw new RuntimeException('9Router embedding response had no data.0.embedding array.');
        }

        return $vector;
    }
}
