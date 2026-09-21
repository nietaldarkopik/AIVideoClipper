<?php

namespace App\Services\Research\Engine;

use App\Services\Research\DTOs\ResearchItem;
use App\Services\Research\DTOs\ResearchQuery;

/**
 * Deduplicates raw results and clusters what remains into topics.
 *
 * Greedy single-pass clustering against each existing cluster's token set, not a
 * full pairwise/hierarchical pass: a run handles a few hundred items at most, and
 * greedy clustering is O(n·k) with an obvious, debuggable outcome. The trade-off
 * is order sensitivity, which is why items are sorted by engagement first — the
 * strongest item seeds each cluster instead of whichever one happened to arrive
 * first from the fastest provider.
 */
class TopicExtractor
{
    /**
     * Drop results that are the same thing twice: identical URLs, or near-identical
     * titles syndicated across outlets.
     *
     * @param  ResearchItem[]  $items
     * @return ResearchItem[]
     */
    public function deduplicate(array $items): array
    {
        $seenUrls = [];
        $seenFingerprints = [];
        $unique = [];

        foreach ($items as $item) {
            if ($item->title === '' || $item->url === '') {
                continue;
            }

            $url = $this->canonicalUrl($item->url);
            if (isset($seenUrls[$url])) {
                continue;
            }

            // Title dedupe is scoped PER SOURCE, never globally. The same headline
            // arriving from Reddit, Google News and YouTube is not noise — it is the
            // cross-source corroboration the whole engine is built to detect, and
            // collapsing it globally would leave every corroborated topic looking like
            // a single-source one. Within one source it really is a duplicate
            // (syndicated across a feed's own pages), so it is dropped there.
            $fingerprint = $item->sourceKey.'|'.TextSignature::fingerprint($item->title);

            // A fingerprint with no tokens means the title was all stopwords or very
            // short words — keep the item rather than collapsing every such title into
            // one bucket.
            $hasTokens = ! str_ends_with($fingerprint, '|');

            if ($hasTokens && isset($seenFingerprints[$fingerprint])) {
                continue;
            }

            $seenUrls[$url] = true;
            if ($hasTokens) {
                $seenFingerprints[$fingerprint] = true;
            }
            $unique[] = $item;
        }

        return $unique;
    }

    /**
     * Remove anything matching the channel's excluded keywords. Applied after
     * retrieval rather than as a negative search term because most providers have no
     * exclusion syntax, and the ones that do apply it inconsistently.
     *
     * @param  ResearchItem[]  $items
     * @return ResearchItem[]
     */
    public function applyExclusions(array $items, ResearchQuery $query): array
    {
        $excluded = array_filter(array_map('mb_strtolower', $query->excludedKeywords));

        if ($excluded === []) {
            return $items;
        }

        return array_values(array_filter($items, function (ResearchItem $item) use ($excluded) {
            $haystack = mb_strtolower($item->searchableText());

            foreach ($excluded as $keyword) {
                if ($keyword !== '' && str_contains($haystack, $keyword)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param  ResearchItem[]  $items
     * @param  array<string, float>  $sourceWeights  source key => configured weight
     * @return ResearchTopic[]
     */
    public function cluster(array $items, array $sourceWeights = []): array
    {
        $threshold = (float) config('research.topics.cluster_threshold', 0.34);

        $items = $this->sortByStrength($items);

        /** @var ResearchTopic[] $topics */
        $topics = [];

        foreach ($items as $item) {
            $tokens = TextSignature::tokens($item->searchableText());

            if ($tokens === []) {
                continue;
            }

            $matched = null;
            $bestSimilarity = $threshold;

            foreach ($topics as $topic) {
                $similarity = TextSignature::similarity($tokens, $topic->tokens);
                if ($similarity >= $bestSimilarity) {
                    $bestSimilarity = $similarity;
                    $matched = $topic;
                }
            }

            if ($matched === null) {
                $matched = new ResearchTopic($item->title, $tokens);
                $topics[] = $matched;
            }

            $matched->add($item, $sourceWeights[$item->sourceKey] ?? 1.0);
        }

        // Relabel from the cluster's strongest item once every member is in, so a
        // topic seeded by a terse headline still gets a representative label.
        foreach ($topics as $topic) {
            $representative = $topic->representative();
            if ($representative !== null) {
                $topic->label = $representative->title;
            }
        }

        return $topics;
    }

    /**
     * @param  ResearchItem[]  $items
     * @return ResearchItem[]
     */
    private function sortByStrength(array $items): array
    {
        usort($items, function (ResearchItem $a, ResearchItem $b) {
            return $this->strengthOf($b) <=> $this->strengthOf($a);
        });

        return $items;
    }

    private function strengthOf(ResearchItem $item): int
    {
        // Views are orders of magnitude larger than upvotes, so they are compressed
        // before being compared — otherwise a single YouTube video would always seed
        // every cluster ahead of a heavily-discussed Reddit thread.
        $views = (int) ($item->engagement['views'] ?? 0);
        $score = (int) ($item->engagement['score'] ?? 0);

        return $score + ($views > 0 ? (int) sqrt($views) : 0);
    }

    /**
     * Strips the query string and trailing slash so the same article arriving with
     * different UTM tags is recognized as one result.
     */
    private function canonicalUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return mb_strtolower(trim($url));
        }

        return mb_strtolower(($parts['host'] ?? '').rtrim($parts['path'] ?? '', '/'));
    }
}
