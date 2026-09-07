<?php

namespace App\Services\Research\Providers;

use App\Services\Research\DTOs\ResearchItem;
use App\Services\Research\DTOs\ResearchQuery;
use Carbon\CarbonImmutable;

/**
 * Reddit's public read-only JSON endpoints (.json suffix) — no OAuth app needed,
 * which is why this is one of the sources that works with zero setup.
 *
 * Two modes, both driven by per-channel config: when subreddits are configured we
 * pull each one's hot/top listing (the "what is my community talking about" signal);
 * otherwise we fall back to site-wide keyword search.
 */
class RedditResearchProvider extends AbstractHttpResearchProvider
{
    private const BASE = 'https://www.reddit.com';

    public function key(): string
    {
        return 'reddit';
    }

    public function label(): string
    {
        return 'Reddit';
    }

    public function type(): string
    {
        return 'social';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'subreddits', 'label' => 'Subreddits', 'type' => 'list', 'help' => 'Tanpa awalan r/. Kosongkan untuk memakai pencarian global.'],
            ['key' => 'min_score', 'label' => 'Minimum Upvotes', 'type' => 'number', 'default' => 50],
            ['key' => 'listing', 'label' => 'Listing', 'type' => 'select', 'options' => ['hot', 'top', 'new'], 'default' => 'hot'],
            ['key' => 'time_filter', 'label' => 'Periode (untuk top)', 'type' => 'select', 'options' => ['hour', 'day', 'week', 'month'], 'default' => 'day'],
        ];
    }

    public function search(ResearchQuery $query): array
    {
        $subreddits = array_map(
            fn (string $sub) => ltrim(trim($sub), '/rR/'),
            $query->optionList('subreddits'),
        );

        return $subreddits === []
            ? $this->searchGlobally($query)
            : $this->fetchSubreddits($subreddits, $query);
    }

    public function trending(ResearchQuery $query): array
    {
        $subreddits = $query->optionList('subreddits');

        // Without configured subreddits there is no meaningful channel-specific
        // "trending" on Reddit — r/all would just inject noise from every niche.
        return $subreddits === [] ? [] : $this->search($query);
    }

    /**
     * @param  string[]  $subreddits
     * @return ResearchItem[]
     */
    private function fetchSubreddits(array $subreddits, ResearchQuery $query): array
    {
        $listing = (string) $query->option('listing', 'hot');
        $perSub = max(5, (int) ceil($query->limit / max(1, count($subreddits))));
        $items = [];

        foreach ($subreddits as $subreddit) {
            $params = ['limit' => $perSub, 'raw_json' => 1];
            if ($listing === 'top') {
                $params['t'] = (string) $query->option('time_filter', 'day');
            }

            $payload = $this->getJson(self::BASE."/r/{$subreddit}/{$listing}.json", $params);

            foreach ($this->mapListing($payload, $query) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return ResearchItem[]
     */
    private function searchGlobally(ResearchQuery $query): array
    {
        $items = [];

        foreach ($query->primaryTerms(3) as $term) {
            $payload = $this->getJson(self::BASE.'/search.json', [
                'q' => $term,
                'sort' => 'top',
                // Reddit's search `t` is coarse (hour/day/week/...), so map the query's
                // lookback onto the smallest bucket that covers it rather than passing
                // an hour count it would silently ignore.
                't' => $query->lookbackHours <= 24 ? 'day' : ($query->lookbackHours <= 168 ? 'week' : 'month'),
                'limit' => max(5, (int) ceil($query->limit / max(1, count($query->primaryTerms(3))))),
                'raw_json' => 1,
            ]);

            foreach ($this->mapListing($payload, $query) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param  array<mixed>  $payload
     * @return ResearchItem[]
     */
    private function mapListing(array $payload, ResearchQuery $query): array
    {
        $minScore = (int) $query->option('min_score', 0);
        $items = [];

        foreach ($payload['data']['children'] ?? [] as $child) {
            $post = $child['data'] ?? null;
            if (! is_array($post) || ($post['stickied'] ?? false)) {
                continue;
            }

            $score = (int) ($post['score'] ?? 0);
            if ($score < $minScore) {
                continue;
            }

            $permalink = (string) ($post['permalink'] ?? '');
            if ($permalink === '') {
                continue;
            }

            $items[] = $this->item(
                title: (string) ($post['title'] ?? ''),
                // Always the Reddit discussion permalink, never post.url: for a link
                // post the latter points at the article, which loses the discussion
                // signal that made this a research hit in the first place.
                url: self::BASE.$permalink,
                externalId: (string) ($post['id'] ?? ''),
                summary: mb_substr(trim((string) ($post['selftext'] ?? '')), 0, 800) ?: null,
                author: isset($post['author']) ? 'u/'.$post['author'] : null,
                publishedAt: isset($post['created_utc']) ? CarbonImmutable::createFromTimestampUTC((int) $post['created_utc']) : null,
                engagement: [
                    'score' => $score,
                    'comments' => (int) ($post['num_comments'] ?? 0),
                    'upvote_ratio' => (float) ($post['upvote_ratio'] ?? 0),
                ],
                raw: ['subreddit' => $post['subreddit'] ?? null, 'flair' => $post['link_flair_text'] ?? null],
            );
        }

        return $items;
    }
}
