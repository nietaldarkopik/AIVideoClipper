<?php

namespace App\Services\Trending\Contracts;

use App\Services\Trending\TrendingItem;

interface TrendingProvider
{
    public function platform(): string;

    public function label(): string;

    /**
     * False only for the real YouTube Data API provider — every other platform
     * has no viable free/ToS-safe trending API, so it stays mock until a real
     * integration is swapped in (see TrendingServiceProvider).
     */
    public function isMocked(): bool;

    /**
     * @param  array<string, mixed>  $filters
     * @return TrendingItem[]
     */
    public function fetchTrending(array $filters = []): array;
}
