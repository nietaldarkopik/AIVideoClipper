<?php

namespace App\Services\Research\DTOs;

use Carbon\CarbonImmutable;

/**
 * One normalized item retrieved from a research provider.
 *
 * Every field must come from the provider response. Nothing in the pipeline may
 * synthesize a URL, a headline, or an engagement number (spec section 22) — a
 * provider that cannot supply a metric leaves it null rather than guessing.
 *
 * A plain object, not an array: JsonResource::toArray() reads fields via magic
 * __get(), which only works against object properties (same reasoning as
 * App\Services\Trending\TrendingItem).
 */
class ResearchItem
{
    /**
     * @param  array<string, int|float|string>  $engagement  as retrieved, e.g. ['score' => 812, 'comments' => 240]
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $sourceKey,
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $externalId = null,
        public readonly ?string $summary = null,
        public readonly ?string $author = null,
        public readonly ?CarbonImmutable $publishedAt = null,
        public readonly array $engagement = [],
        public readonly array $raw = [],
    ) {}

    /**
     * Text used for topic clustering and relevance scoring. Title carries the signal;
     * the summary is included but truncated so a long article body can't drown out
     * the headline's tokens.
     */
    public function searchableText(): string
    {
        return trim($this->title.' '.mb_substr((string) $this->summary, 0, 400));
    }
}
