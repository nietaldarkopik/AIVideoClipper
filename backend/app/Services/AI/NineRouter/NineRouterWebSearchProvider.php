<?php

namespace App\Services\AI\NineRouter;

use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Contracts\WebSearchProvider;
use App\Services\AI\DTOs\WebSearchResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Real web search via a self-hosted 9Router instance's /v1/search endpoint
 * (Tavily/etc. behind it, chosen by services.nine_router.web_search_model — a
 * provider name, not a chat model id). Formalizes the same HTTP call already used
 * ad-hoc by App\Services\Trending\Providers\AbstractNineRouterSearchTrendingProvider,
 * as a reusable capability for content-research (not just trending discovery).
 */
class NineRouterWebSearchProvider implements WebSearchProvider
{
    use LogsAiRequests;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly string $model = '',
    ) {
    }

    public function search(string $query, int $maxResults = 10, ?string $domainFilter = null): array
    {
        if (empty($this->model)) {
            throw new RuntimeException(
                'NINE_ROUTER_WEB_SEARCH_MODEL is not set. Check GET ' . rtrim($this->baseUrl, '/') .
                '/models/web (kind=="webSearch") for the provider names your 9Router instance actually has credentials for.'
            );
        }

        $span = $this->aiLogger()->start('web_search', 'nine_router', $this->model, $query);

        $request = Http::timeout(30)
            ->retry(2, 1500)
            ->withOptions(['version' => 1.1]);

        if ($this->apiKey) {
            $request = $request->withToken($this->apiKey);
        }

        $payload = [
            'model' => $this->model,
            'query' => $query,
            'max_results' => $maxResults,
        ];
        if ($domainFilter) {
            $payload['domain_filter'] = $domainFilter;
        }

        try {
            $response = $request->post(rtrim($this->baseUrl, '/') . '/search', $payload);
        } catch (Throwable $e) {
            $span->failure($e->getMessage());

            throw new RuntimeException('9Router web search failed: ' . $e->getMessage());
        }

        if ($response->failed()) {
            $span->failure($response->body());

            throw new RuntimeException('9Router web search failed: ' . $response->body());
        }

        // Flat top-level object — {provider, query, results, answer, usage, metrics,
        // errors} — no "data" wrapper, unlike /v1/web/fetch.
        $results = $response->json('results', []);
        $span->success('results: ' . count($results ?? []));

        $mapped = array_values(array_filter(array_map(
            fn (array $r) => $this->toResult($r),
            is_array($results) ? $results : []
        )));

        // Some queries (observed: sensitive/complex multi-topic ones) come back
        // with an empty results array even though the underlying grounding search
        // still produced a synthesized "answer" — a web-grounded LLM summary, not
        // tied to any single citable URL. Falling back to it here means the topic
        // still gets *some* real research context instead of silently having zero
        // sources (which otherwise forces the narrative provider onto pure general
        // knowledge with no grounding at all).
        if (empty($mapped)) {
            $answerText = trim((string) $response->json('answer.text', ''));
            if ($answerText !== '') {
                $mapped[] = new WebSearchResult(
                    title: 'Ringkasan hasil pencarian AI',
                    url: '',
                    snippet: $answerText,
                );
            }
        }

        return $mapped;
    }

    private function toResult(array $r): ?WebSearchResult
    {
        $url = $r['url'] ?? null;
        $title = $r['title'] ?? null;
        if (! is_string($url) || $url === '' || ! is_string($title) || $title === '') {
            return null;
        }

        $publishedAt = $r['published_at'] ?? null;

        return new WebSearchResult(
            title: $title,
            url: $url,
            snippet: $r['content'] ?? $r['snippet'] ?? $r['text'] ?? null,
            published_at: is_string($publishedAt) ? $publishedAt : null,
            author: $r['metadata']['author'] ?? null,
            thumbnail_url: $r['metadata']['image_url'] ?? null,
        );
    }
}
