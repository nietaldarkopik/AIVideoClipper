<?php

namespace App\Services\Research\Providers;

use App\Services\Research\DTOs\ResearchQuery;
use Carbon\CarbonImmutable;

/**
 * Hacker News via the official Algolia search API (hn.algolia.com) — public, no
 * key. search_by_date is used rather than plain relevance search because research
 * cares about what surfaced recently, not the all-time best match.
 */
class HackerNewsResearchProvider extends AbstractHttpResearchProvider
{
    private const BASE = 'https://hn.algolia.com/api/v1';

    public function key(): string
    {
        return 'hacker_news';
    }

    public function label(): string
    {
        return 'Hacker News';
    }

    public function type(): string
    {
        return 'news';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'min_points', 'label' => 'Minimum Points', 'type' => 'number', 'default' => 20],
        ];
    }

    public function search(ResearchQuery $query): array
    {
        $minPoints = (int) $query->option('min_points', 0);
        $since = CarbonImmutable::now()->subHours(max(1, $query->lookbackHours))->getTimestamp();
        $items = [];

        foreach ($query->primaryTerms(3) as $term) {
            $payload = $this->getJson(self::BASE.'/search_by_date', [
                'query' => $term,
                'tags' => 'story',
                'numericFilters' => "created_at_i>{$since},points>={$minPoints}",
                'hitsPerPage' => max(5, (int) ceil($query->limit / 3)),
            ]);

            foreach ($payload['hits'] ?? [] as $hit) {
                $objectId = (string) ($hit['objectID'] ?? '');
                $title = (string) ($hit['title'] ?? '');

                if ($objectId === '' || $title === '') {
                    continue;
                }

                $items[] = $this->item(
                    title: $title,
                    // A story without an external url is a text post; its HN item page is
                    // then the only real URL, so fall back to that rather than dropping it.
                    url: (string) ($hit['url'] ?? '') ?: 'https://news.ycombinator.com/item?id='.$objectId,
                    externalId: $objectId,
                    summary: isset($hit['story_text']) ? mb_substr(strip_tags((string) $hit['story_text']), 0, 600) : null,
                    author: $hit['author'] ?? null,
                    publishedAt: isset($hit['created_at_i']) ? CarbonImmutable::createFromTimestampUTC((int) $hit['created_at_i']) : null,
                    engagement: [
                        'score' => (int) ($hit['points'] ?? 0),
                        'comments' => (int) ($hit['num_comments'] ?? 0),
                    ],
                    raw: ['discussion_url' => 'https://news.ycombinator.com/item?id='.$objectId],
                );
            }
        }

        return array_slice($items, 0, $query->limit);
    }

    public function trending(ResearchQuery $query): array
    {
        $payload = $this->getJson(self::BASE.'/search', ['tags' => 'front_page', 'hitsPerPage' => $query->limit]);
        $items = [];

        foreach ($payload['hits'] ?? [] as $hit) {
            $objectId = (string) ($hit['objectID'] ?? '');
            if ($objectId === '' || ($hit['title'] ?? '') === '') {
                continue;
            }

            $items[] = $this->item(
                title: (string) $hit['title'],
                url: (string) ($hit['url'] ?? '') ?: 'https://news.ycombinator.com/item?id='.$objectId,
                externalId: $objectId,
                author: $hit['author'] ?? null,
                publishedAt: isset($hit['created_at']) ? $this->parseDate((string) $hit['created_at']) : null,
                engagement: ['score' => (int) ($hit['points'] ?? 0), 'comments' => (int) ($hit['num_comments'] ?? 0)],
                raw: ['front_page' => true],
            );
        }

        return $items;
    }
}
