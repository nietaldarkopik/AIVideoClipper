<?php

namespace App\Services\AI\Mock;

use App\Services\AI\Contracts\WebSearchProvider;
use App\Services\AI\DTOs\WebSearchResult;

/**
 * Zero-cost placeholder: deterministic fake results naming the query, no real
 * network call — same role as every other mock provider in this app. Real search
 * needs AI_WEB_SEARCH_PROVIDER=nine_router.
 */
class MockWebSearchProvider implements WebSearchProvider
{
    public function search(string $query, int $maxResults = 10, ?string $domainFilter = null): array
    {
        $count = min($maxResults, 5);
        // When a domain_filter is given (e.g. RelevantVideoFinder asking for
        // youtube.com/tiktok.com/instagram.com), a real search would only return
        // pages on those hosts — mimic that here instead of always using
        // example.com, so callers that filter results by host (like
        // RelevantVideoFinder) still see believable mock data.
        $domains = $domainFilter ? array_values(array_filter(array_map('trim', explode(',', $domainFilter)))) : ['example.com'];

        $results = [];
        for ($i = 1; $i <= $count; $i++) {
            $domain = $domains[($i - 1) % count($domains)];
            $results[] = new WebSearchResult(
                title: "(Mock) Result {$i} for \"{$query}\"",
                url: "https://{$domain}/mock-result-{$i}",
                snippet: "This is a mock search result snippet #{$i} for the query \"{$query}\". No real web search was performed.",
                published_at: now()->subDays($i)->toIso8601String(),
                author: 'Mock Author',
                thumbnail_url: null,
            );
        }

        return $results;
    }
}
