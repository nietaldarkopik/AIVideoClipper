<?php

namespace App\Services\Research\Providers;

use App\Services\Research\DTOs\ResearchItem;
use App\Services\Research\DTOs\ResearchQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Two modes, both driven by per-channel config: when subreddits are configured we
 * pull each one's hot/top listing (the "what is my community talking about" signal);
 * otherwise we fall back to site-wide keyword search.
 *
 * Also two TRANSPORTS, picked automatically:
 *
 * - No credentials -> the public read-only .json endpoints on www.reddit.com.
 *   Zero setup, which is why this source worked out of the box.
 * - REDDIT_CLIENT_ID/SECRET set -> OAuth against oauth.reddit.com.
 *
 * The second exists because Reddit blocks the public endpoints from datacenter and
 * VPN IP ranges with a flat 403 that no User-Agent gets past, while its OAuth API
 * answers normally from those same addresses. Proxying is not a workaround here:
 * free proxy pools are datacenter IPs by definition and hit the identical block.
 * Credentials are free — a "script" app at https://www.reddit.com/prefs/apps — and
 * they also raise the rate limit from ~10 to 100 requests/minute.
 *
 * The two APIs return the same listing JSON, so only the base URL, the path suffix
 * and the auth header differ; mapListing() is shared verbatim.
 */
class RedditResearchProvider extends AbstractHttpResearchProvider
{
    private const BASE = 'https://www.reddit.com';

    private const OAUTH_BASE = 'https://oauth.reddit.com';

    private const TOKEN_URL = 'https://www.reddit.com/api/v1/access_token';

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

            $payload = $this->fetch("/r/{$subreddit}/{$listing}", $params);

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
            $payload = $this->fetch('/search', [
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
     * One listing fetch, over whichever transport is available. $path is given
     * WITHOUT the `.json` suffix: the public API needs it, the OAuth API rejects
     * it, so it is appended only on the branch that wants it.
     *
     * @param  array<string, mixed>  $params
     * @return array<mixed>
     */
    private function fetch(string $path, array $params): array
    {
        $token = $this->accessToken();

        if ($token === null) {
            return $this->getJson(self::BASE.$path.'.json', $params);
        }

        return $this->getJson(
            self::OAUTH_BASE.$path,
            $params,
            ['Authorization' => 'Bearer '.$token],
        );
    }

    /**
     * A client-credentials token, or null when no app is configured (the caller
     * then falls back to the public endpoints).
     *
     * Cached just under the token's own lifetime so a research run reuses one
     * token across every subreddit instead of authenticating per request. The
     * 60-second haircut covers the gap between issuing and using it.
     */
    private function accessToken(): ?string
    {
        $clientId = config('research.providers.reddit.client_id');
        $clientSecret = config('research.providers.reddit.client_secret');

        if (! $clientId || ! $clientSecret) {
            return null;
        }

        $cacheKey = 'research:reddit:token:'.md5((string) $clientId);
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->http()
            ->withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->post(self::TOKEN_URL, ['grant_type' => 'client_credentials'])
            ->json();

        $token = $response['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            // Surfaced as a source failure the same way any other provider error is,
            // rather than silently degrading to the public endpoints — which are
            // exactly what the credentials were added to get away from.
            throw new RuntimeException('Reddit menolak kredensial OAuth (periksa REDDIT_CLIENT_ID / REDDIT_CLIENT_SECRET).');
        }

        // Stored just under the token's stated lifetime, so a run reuses one token
        // across every subreddit instead of authenticating per request. The
        // 60-second haircut covers the gap between issuing and using it.
        Cache::put($cacheKey, $token, max(60, (int) ($response['expires_in'] ?? 3600) - 60));

        return $token;
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
