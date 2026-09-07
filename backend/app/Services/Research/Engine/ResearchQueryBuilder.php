<?php

namespace App\Services\Research\Engine;

use App\Models\ContentChannel;
use App\Models\ResearchSource;
use App\Services\Research\DTOs\ResearchQuery;

/**
 * Turns a channel's stored configuration into the ResearchQuery handed to each
 * provider. This is the single place that reads the ContentChannel model on the
 * retrieval path, which is what keeps providers channel-agnostic.
 */
class ResearchQueryBuilder
{
    public function build(ContentChannel $channel, ResearchSource $source): ResearchQuery
    {
        return new ResearchQuery(
            keywords: $this->keywordsFor($channel),
            excludedKeywords: $this->normalizeList($channel->excluded_keywords),
            niche: $channel->niche,
            language: $channel->language ?: 'id',
            region: $this->regionFor($channel),
            limit: 20,
            lookbackHours: $this->lookbackHoursFor($channel),
            config: $this->mergedConfig($channel, $source),
        );
    }

    /**
     * Search terms, most specific first: explicit keywords, then sub-niches, then
     * the broad niche as a last resort. Providers take only the first few terms, so
     * ordering here is what decides what actually gets queried.
     *
     * @return string[]
     */
    public function keywordsFor(ContentChannel $channel): array
    {
        $keywords = array_merge(
            $this->normalizeList($channel->keywords),
            $this->normalizeList($channel->sub_niches),
            array_filter([$channel->niche]),
        );

        $unique = [];
        foreach ($keywords as $keyword) {
            $lower = mb_strtolower($keyword);
            if (! isset($unique[$lower])) {
                $unique[$lower] = $keyword;
            }
        }

        return array_values($unique);
    }

    /**
     * Per-channel pivot configuration layered over the source's global defaults.
     * The channel wins on every key — a global default must never override what the
     * user explicitly set for this channel.
     *
     * @return array<string, mixed>
     */
    public function mergedConfig(ContentChannel $channel, ResearchSource $source): array
    {
        $global = is_array($source->configuration) ? $source->configuration : [];

        $pivot = $source->pivot?->configuration ?? null;
        if (! is_array($pivot)) {
            $pivot = [];
        }

        // Shallow merge on purpose: these config values are scalars and flat lists, and
        // a recursive merge would silently concatenate a channel's subreddit list onto
        // the global one instead of replacing it.
        return array_merge($global, $pivot);
    }

    /**
     * How far back a run looks. Channels that research several times a day are
     * chasing fresh topics, so their window is tighter — otherwise the 12:00 run
     * would re-surface everything the 06:00 run already covered.
     */
    private function lookbackHoursFor(ContentChannel $channel): int
    {
        $runsPerDay = max(1, count($channel->scheduleTimes()));

        return match (true) {
            $runsPerDay >= 4 => 12,
            $runsPerDay >= 2 => 24,
            default => 48,
        };
    }

    private function regionFor(ContentChannel $channel): string
    {
        // Derived from the channel's language rather than stored separately: an
        // Indonesian-language channel wants Indonesian regional trends. A channel that
        // needs otherwise overrides `region` in its per-source configuration.
        return match ($channel->language) {
            'id' => 'ID',
            'en' => 'US',
            default => strtoupper(mb_substr($channel->language ?: 'id', 0, 2)),
        };
    }

    /**
     * @return string[]
     */
    private function normalizeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_string($item) ? trim($item) : null,
            $value,
        ), fn ($item) => is_string($item) && $item !== ''));
    }
}
