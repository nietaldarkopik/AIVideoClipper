<?php

namespace App\Services\AI\DTOs;

class WebSearchResult
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $snippet = null,
        public readonly ?string $published_at = null,
        public readonly ?string $author = null,
        public readonly ?string $thumbnail_url = null,
    ) {
    }
}
