<?php

namespace App\Services\Research\Providers;

use App\Services\Research\DTOs\ResearchQuery;

/**
 * Google Trends' public "daily trends" RSS feed. Google has no free official
 * Trends API, and the internal /api/explore endpoints require a token handshake
 * and are not ToS-safe to scrape — the published RSS feed is the officially
 * available surface, so that is what this uses.
 *
 * Consequence: this provider answers "what is trending in this region right now",
 * not "how is my keyword trending". Keyword relevance is applied by the engine's
 * scoring stage against the retrieved trends, never by fabricating a search-volume
 * number Google did not give us.
 */
class GoogleTrendsResearchProvider extends AbstractHttpResearchProvider
{
    private const FEED = 'https://trends.google.com/trending/rss';

    public function key(): string
    {
        return 'google_trends';
    }

    public function label(): string
    {
        return 'Google Trends';
    }

    public function type(): string
    {
        return 'trends';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => 'Region', 'type' => 'text', 'default' => 'ID', 'help' => 'Kode negara dua huruf, mis. ID atau US.'],
        ];
    }

    public function search(ResearchQuery $query): array
    {
        $region = strtoupper((string) ($query->option('region') ?: $query->region));

        $items = [];

        foreach ($this->getFeed(self::FEED.'?geo='.urlencode($region)) as $entry) {
            $items[] = $this->item(
                title: $entry['title'],
                // The feed's <link> is the Trends explore page for the term. Kept as the
                // evidence URL because it is what actually substantiates "this is
                // trending" — the related news links live in the description.
                url: $entry['link'] !== '' ? $entry['link'] : 'https://trends.google.com/trending?geo='.urlencode($region),
                externalId: mb_strtolower($entry['title']),
                summary: $entry['description'],
                publishedAt: $entry['published_at'],
                // No traffic figure is exposed by the RSS feed, so none is recorded.
                // Inventing one here would poison every downstream score.
                raw: ['region' => $region],
            );
        }

        return array_slice($items, 0, $query->limit);
    }

    public function trending(ResearchQuery $query): array
    {
        // This feed is inherently a trending feed — search() and trending() are the
        // same call, so returning [] here would drop the source from trending mode.
        return $this->search($query);
    }
}
