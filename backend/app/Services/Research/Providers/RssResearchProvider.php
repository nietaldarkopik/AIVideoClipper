<?php

namespace App\Services\Research\Providers;

use App\Services\Research\DTOs\ResearchQuery;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generic feed reader: the escape hatch that lets a channel add any publication
 * (tech news, academic feeds, a competitor's channel feed) without a new provider
 * class. Feed URLs are per-channel configuration.
 */
class RssResearchProvider extends AbstractHttpResearchProvider
{
    public function key(): string
    {
        return 'rss';
    }

    public function label(): string
    {
        return 'RSS / Atom';
    }

    public function type(): string
    {
        return 'news';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'feed_urls', 'label' => 'Feed URLs', 'type' => 'list', 'help' => 'Satu URL per baris (RSS atau Atom).'],
            ['key' => 'match_keywords', 'label' => 'Hanya item yang cocok kata kunci', 'type' => 'boolean', 'default' => false],
        ];
    }

    protected function healthCheckConfig(): array
    {
        // Nothing to fetch without a feed, so the health check uses a stable, public
        // one purely to prove the fetch+parse path works.
        return ['feed_urls' => ['https://news.ycombinator.com/rss']];
    }

    public function search(ResearchQuery $query): array
    {
        $feeds = $query->optionList('feed_urls');

        if ($feeds === []) {
            return [];
        }

        $matchKeywords = (bool) $query->option('match_keywords', false);
        $keywords = array_map('mb_strtolower', $query->keywords);
        $perFeed = max(3, (int) ceil($query->limit / count($feeds)));
        $items = [];

        foreach ($feeds as $feedUrl) {
            if (! filter_var($feedUrl, FILTER_VALIDATE_URL)) {
                continue;
            }

            try {
                $entries = $this->getFeed($feedUrl);
            } catch (Throwable $e) {
                // One dead feed among ten must not fail the source: the user configured
                // these URLs by hand and any of them can rot at any time. Logged so the
                // failure is still visible, then skipped.
                Log::warning('research.rss.feed_failed', ['feed' => $feedUrl, 'error' => $e->getMessage()]);

                continue;
            }

            $taken = 0;

            foreach ($entries as $entry) {
                if ($matchKeywords && ! $this->matches($entry['title'].' '.$entry['description'], $keywords)) {
                    continue;
                }

                $items[] = $this->item(
                    title: $entry['title'],
                    url: $entry['link'],
                    summary: $entry['description'],
                    publishedAt: $entry['published_at'],
                    raw: ['feed' => $feedUrl],
                );

                if (++$taken >= $perFeed) {
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * @param  string[]  $keywords
     */
    private function matches(string $haystack, array $keywords): bool
    {
        if ($keywords === []) {
            return true;
        }

        $haystack = mb_strtolower($haystack);

        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
