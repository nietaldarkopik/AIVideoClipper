<?php

namespace App\Services\Research\Providers;

use App\Services\Research\DTOs\ResearchQuery;

/**
 * Google News' published RSS search feed — no key, and language/region aware, so
 * an Indonesian channel gets Indonesian coverage without a separate provider.
 */
class GoogleNewsResearchProvider extends AbstractHttpResearchProvider
{
    private const BASE = 'https://news.google.com/rss';

    public function key(): string
    {
        return 'google_news';
    }

    public function label(): string
    {
        return 'Google News';
    }

    public function type(): string
    {
        return 'news';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => 'Region', 'type' => 'text', 'default' => 'ID'],
            ['key' => 'extra_terms', 'label' => 'Kata Kunci Tambahan', 'type' => 'list'],
        ];
    }

    public function search(ResearchQuery $query): array
    {
        $region = strtoupper((string) ($query->option('region') ?: $query->region));
        $language = $query->language ?: 'id';
        $terms = array_merge($query->primaryTerms(4), $query->optionList('extra_terms'));
        $items = [];

        foreach (array_slice(array_unique($terms), 0, 5) as $term) {
            // "when:{n}h" is Google News' own recency operator — it filters server-side,
            // which matters because the feed caps at ~100 items regardless of age.
            $q = $term.' when:'.max(1, $query->lookbackHours).'h';

            $url = self::BASE.'/search?q='.urlencode($q)
                .'&hl='.urlencode($language.'-'.$region)
                .'&gl='.urlencode($region)
                .'&ceid='.urlencode($region.':'.$language);

            foreach ($this->getFeed($url) as $entry) {
                $items[] = $this->item(
                    title: $entry['title'],
                    url: $entry['link'],
                    summary: $entry['description'],
                    // Google News titles are "Headline - Publisher"; the publisher half is
                    // the only attribution the feed gives, so recover it rather than
                    // leaving the source unattributed.
                    author: $this->publisherFromTitle($entry['title']),
                    publishedAt: $entry['published_at'],
                    raw: ['query' => $q, 'region' => $region],
                );
            }
        }

        return array_slice($items, 0, $query->limit);
    }

    private function publisherFromTitle(string $title): ?string
    {
        $position = mb_strrpos($title, ' - ');

        if ($position === false) {
            return null;
        }

        return trim(mb_substr($title, $position + 3)) ?: null;
    }
}
