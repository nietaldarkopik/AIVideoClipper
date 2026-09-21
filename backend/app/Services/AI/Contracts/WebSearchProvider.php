<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\DTOs\WebSearchResult;

interface WebSearchProvider
{
    /**
     * @return WebSearchResult[]
     */
    public function search(string $query, int $maxResults = 10, ?string $domainFilter = null): array;
}
