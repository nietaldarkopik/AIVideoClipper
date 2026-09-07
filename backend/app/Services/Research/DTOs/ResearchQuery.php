<?php

namespace App\Services\Research\DTOs;

/**
 * What one provider is being asked to look for, assembled from the channel's own
 * configuration by ResearchQueryBuilder. Providers read only from this — they never
 * touch the ContentChannel model, which is what keeps them channel-agnostic and
 * makes "adding a channel needs no code change" true.
 */
class ResearchQuery
{
    /**
     * @param  string[]  $keywords  channel keywords + niche + sub-niches
     * @param  string[]  $excludedKeywords  filtered out post-retrieval
     * @param  array<string, mixed>  $config  merged source config (global row <- per-channel pivot)
     */
    public function __construct(
        public readonly array $keywords,
        public readonly array $excludedKeywords = [],
        public readonly ?string $niche = null,
        public readonly string $language = 'id',
        public readonly string $region = 'ID',
        public readonly int $limit = 20,
        public readonly int $lookbackHours = 48,
        public readonly array $config = [],
    ) {}

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * @return string[]
     */
    public function optionList(string $key): array
    {
        $value = $this->config[$key] ?? [];

        if (is_string($value)) {
            // Textarea-entered config arrives newline- or comma-separated from the UI.
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? trim($v) : null,
            $value,
        ), fn ($v) => is_string($v) && $v !== ''));
    }

    /**
     * The first few keywords, for providers whose API takes a single query string.
     * Sending all of them produces an over-constrained query that returns nothing.
     */
    public function primaryTerms(int $count = 3): array
    {
        return array_slice($this->keywords, 0, max(1, $count));
    }
}
