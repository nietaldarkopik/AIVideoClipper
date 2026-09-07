<?php

namespace App\Services\Research\Providers;

use App\Services\AI\Contracts\WebSearchProvider;
use App\Services\Research\Contracts\ResearchProvider;
use App\Services\Research\DTOs\ProviderHealth;
use App\Services\Research\DTOs\ResearchItem;
use App\Services\Research\DTOs\ResearchQuery;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Bridges the app's existing WebSearchProvider (9Router / mock, already used by
 * the content-brief pipeline) into the research engine, so a channel gets general
 * web coverage without a second search integration to configure.
 *
 * Does not extend AbstractHttpResearchProvider: it makes no HTTP calls of its own,
 * it delegates to whatever AI_WEB_SEARCH_PROVIDER is bound to.
 */
class WebSearchResearchProvider implements ResearchProvider
{
    public function __construct(private readonly WebSearchProvider $webSearch) {}

    public function key(): string
    {
        return 'web_search';
    }

    public function label(): string
    {
        return 'Web Search';
    }

    public function type(): string
    {
        return 'general';
    }

    public function requiresCredentials(): bool
    {
        // The bound provider may need a key, but this class itself never does — and
        // the mock binding works with none, which is the zero-setup default.
        return false;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain_filter', 'label' => 'Batasi ke Domain', 'type' => 'text', 'help' => 'Opsional, mis. detik.com.'],
            ['key' => 'results_per_term', 'label' => 'Hasil per Kata Kunci', 'type' => 'number', 'default' => 6],
        ];
    }

    public function search(ResearchQuery $query): array
    {
        $perTerm = max(1, (int) $query->option('results_per_term', 6));
        $domainFilter = trim((string) $query->option('domain_filter', '')) ?: null;
        $items = [];

        foreach ($query->primaryTerms(3) as $term) {
            foreach ($this->webSearch->search($term, $perTerm, $domainFilter) as $result) {
                if ($result->title === '' || $result->url === '') {
                    continue;
                }

                $items[] = new ResearchItem(
                    sourceKey: $this->key(),
                    title: $result->title,
                    url: $result->url,
                    summary: $result->snippet,
                    author: $result->author,
                    publishedAt: $this->parsePublishedAt($result->published_at),
                    raw: ['query' => $term],
                );
            }
        }

        return array_slice($items, 0, $query->limit);
    }

    public function trending(ResearchQuery $query): array
    {
        // Web search has no trending concept of its own; returning [] keeps the
        // engine from double-counting the same results under two signals.
        return [];
    }

    public function healthCheck(): ProviderHealth
    {
        $startedAt = microtime(true);

        try {
            $results = $this->webSearch->search('teknologi', 3);
            $latency = (int) round((microtime(true) - $startedAt) * 1000);

            return new ProviderHealth($this->key(), true, sprintf('OK - %d hasil dalam %d ms.', count($results), $latency), latencyMs: $latency);
        } catch (Throwable $e) {
            return new ProviderHealth($this->key(), false, $e->getMessage());
        }
    }

    private function parsePublishedAt(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
