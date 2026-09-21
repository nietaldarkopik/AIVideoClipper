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
        // The real NineRouterWebSearchProvider can't use domain_filter (see its
        // docblock) — callers that want platform-restricted results (e.g.
        // RelevantVideoFinder) fold a platform name into the query text instead
        // and filter by host afterward. Mirror that here: pick a domain from
        // whichever known platform name appears in the query, falling back to
        // domain_filter (still honored, in case something else passes it) and
        // finally to a generic domain — so callers that filter results by host
        // still see believable mock data either way.
        $domain = match (true) {
            str_contains($query, 'tiktok') => 'tiktok.com',
            str_contains($query, 'instagram') => 'instagram.com',
            str_contains($query, 'youtube') => 'youtube.com',
            default => explode(',', (string) $domainFilter)[0] ?? '',
        };
        $domains = $domain !== '' ? [$domain] : ['example.com'];

        $results = [];
        for ($i = 1; $i <= $count; $i++) {
            $domain = $domains[($i - 1) % count($domains)];
            $results[] = new WebSearchResult(
                title: "(Mock) Result {$i} for \"{$query}\"",
                // Shaped like the real per-platform video path RelevantVideoFinder's
                // allowlist regex expects (see VIDEO_PATH_PATTERNS), so mock results
                // still pass through that filter for tiktok.com/instagram.com.
                url: match ($domain) {
                    'tiktok.com' => "https://{$domain}/@mockuser/video/{$i}00000000000",
                    'instagram.com' => "https://{$domain}/reel/mock{$i}",
                    default => "https://{$domain}/mock-result-{$i}",
                },
                snippet: "This is a mock search result snippet #{$i} for the query \"{$query}\". No real web search was performed.",
                published_at: now()->subDays($i)->toIso8601String(),
                author: 'Mock Author',
                thumbnail_url: null,
            );
        }

        return $results;
    }
}
