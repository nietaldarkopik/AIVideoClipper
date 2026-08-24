<?php

namespace App\Services\Trending;

use Carbon\CarbonImmutable;

/**
 * A plain DTO, not an array — JsonResource::toArray() accesses fields via magic
 * __get(), which only works against object properties (see TrendingItemResource).
 */
class TrendingItem
{
    public function __construct(
        public readonly string $platform,
        public readonly string $external_id,
        public readonly string $title,
        public readonly string $source_url,
        public readonly ?string $thumbnail_url,
        public readonly ?string $author_name,
        public readonly int $view_count,
        public readonly int $like_count,
        public readonly int $comment_count,
        public readonly ?CarbonImmutable $published_at,
        public readonly bool $is_mock,
    ) {
    }
}
