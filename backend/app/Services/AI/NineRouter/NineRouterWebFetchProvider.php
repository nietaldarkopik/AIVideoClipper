<?php

namespace App\Services\AI\NineRouter;

use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Contracts\WebFetchProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real URL -> markdown/text fetch via a self-hosted 9Router instance's
 * /v1/web/fetch endpoint (Firecrawl/Jina Reader/Tavily Extract/Exa Contents behind
 * it, chosen by services.nine_router.web_fetch_model — a provider name like
 * "jina-reader", not a chat model id).
 */
class NineRouterWebFetchProvider implements WebFetchProvider
{
    use LogsAiRequests;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly string $model = '',
    ) {
    }

    public function fetch(string $url, string $format = 'markdown'): string
    {
        if (empty($this->model)) {
            throw new RuntimeException(
                'NINE_ROUTER_WEB_FETCH_MODEL is not set. Check GET ' . rtrim($this->baseUrl, '/') .
                '/models/web (kind=="webFetch") for the provider names your 9Router instance actually has credentials for.'
            );
        }

        $span = $this->aiLogger()->start('web_fetch', 'nine_router', $this->model, $url);

        $request = Http::timeout(60)
            ->retry(2, 1500)
            ->withOptions(['version' => 1.1]);

        if ($this->apiKey) {
            $request = $request->withToken($this->apiKey);
        }

        $response = $request->post(rtrim($this->baseUrl, '/') . '/web/fetch', [
            'model' => $this->model,
            'url' => $url,
            'format' => $format,
        ]);

        if ($response->failed()) {
            $span->failure($response->body());

            throw new RuntimeException('9Router web fetch failed: ' . $response->body());
        }

        $text = (string) $response->json('data.content.text', '');
        $span->success($text);

        return $text;
    }
}
