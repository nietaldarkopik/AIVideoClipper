<?php

namespace App\Services\Research\Providers;

use App\Services\Research\DTOs\ResearchQuery;

/**
 * Product Hunt's public RSS feed.
 *
 * Their GraphQL API needs an OAuth developer token, which would make this the
 * only tech source requiring setup; the published feed gives the same "what
 * launched today" signal with none. The trade-off is that the feed carries no
 * upvote counts — so none are recorded, rather than estimated.
 */
class ProductHuntResearchProvider extends AbstractHttpResearchProvider
{
    private const FEED = 'https://www.producthunt.com/feed';

    public function key(): string
    {
        return 'product_hunt';
    }

    public function label(): string
    {
        return 'Product Hunt';
    }

    public function type(): string
    {
        return 'general';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'match_keywords', 'label' => 'Hanya produk yang cocok kata kunci', 'type' => 'boolean', 'default' => true],
        ];
    }

    public function search(ResearchQuery $query): array
    {
        $matchKeywords = (bool) $query->option('match_keywords', true);
        $keywords = array_filter(array_map('mb_strtolower', $query->keywords));
        $items = [];

        foreach ($this->getFeed(self::FEED) as $entry) {
            $haystack = mb_strtolower($entry['title'].' '.$entry['description']);

            if ($matchKeywords && $keywords !== []) {
                $hit = false;
                foreach ($keywords as $keyword) {
                    if ($keyword !== '' && str_contains($haystack, $keyword)) {
                        $hit = true;
                        break;
                    }
                }
                if (! $hit) {
                    continue;
                }
            }

            $items[] = $this->item(
                title: $entry['title'],
                url: $entry['link'],
                summary: $entry['description'],
                publishedAt: $entry['published_at'],
            );
        }

        return array_slice($items, 0, $query->limit);
    }

    public function trending(ResearchQuery $query): array
    {
        // The feed is the daily launch list, i.e. inherently the trending view.
        return $this->search($query);
    }
}
