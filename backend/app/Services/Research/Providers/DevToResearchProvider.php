<?php

namespace App\Services\Research\Providers;

use App\Services\Research\DTOs\ResearchQuery;

/**
 * Dev.to (Forem) public articles API — no key required.
 *
 * Forem has no free-text search on the public endpoint, only tag filtering, so
 * keywords are mapped onto tags (lowercased, non-alphanumerics stripped, which is
 * Forem's own tag format). A keyword that is not a real tag simply returns
 * nothing — better than silently returning unrelated articles.
 */
class DevToResearchProvider extends AbstractHttpResearchProvider
{
    private const BASE = 'https://dev.to/api';

    public function key(): string
    {
        return 'devto';
    }

    public function label(): string
    {
        return 'Dev.to';
    }

    public function type(): string
    {
        return 'code';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'tags', 'label' => 'Tag', 'type' => 'list', 'help' => 'Kosongkan untuk memakai kata kunci channel sebagai tag.'],
            ['key' => 'top_days', 'label' => 'Artikel terpopuler dalam (hari)', 'type' => 'number', 'default' => 7],
        ];
    }

    public function search(ResearchQuery $query): array
    {
        $tags = $query->optionList('tags');

        if ($tags === []) {
            $tags = array_values(array_filter(array_map(
                fn (string $keyword) => preg_replace('/[^a-z0-9]/', '', mb_strtolower($keyword)) ?: null,
                $query->primaryTerms(4),
            )));
        }

        if ($tags === []) {
            return [];
        }

        $topDays = max(1, (int) $query->option('top_days', 7));
        $perTag = max(3, (int) ceil($query->limit / count($tags)));
        $items = [];

        foreach ($tags as $tag) {
            $payload = $this->getJson(self::BASE.'/articles', [
                'tag' => $tag,
                'top' => $topDays,
                'per_page' => $perTag,
            ]);

            foreach ($payload as $article) {
                if (! is_array($article) || ($article['title'] ?? '') === '') {
                    continue;
                }

                $items[] = $this->item(
                    title: (string) $article['title'],
                    url: (string) ($article['url'] ?? ''),
                    externalId: (string) ($article['id'] ?? ''),
                    summary: $article['description'] ?? null,
                    author: $article['user']['name'] ?? null,
                    publishedAt: $this->parseDate($article['published_at'] ?? null),
                    engagement: [
                        'score' => (int) ($article['public_reactions_count'] ?? 0),
                        'comments' => (int) ($article['comments_count'] ?? 0),
                    ],
                    raw: ['tags' => $article['tag_list'] ?? []],
                );
            }
        }

        return array_slice($items, 0, $query->limit);
    }
}
