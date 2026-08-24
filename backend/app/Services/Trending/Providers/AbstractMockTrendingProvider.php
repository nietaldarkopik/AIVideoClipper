<?php

namespace App\Services\Trending\Providers;

use App\Services\Trending\Contracts\TrendingProvider;
use App\Services\Trending\TrendingItem;
use Carbon\CarbonImmutable;

/**
 * Fabricates believable trending items from a small curated list of real, publicly
 * live URLs (see each subclass's seedItems()) rather than random ids — a made-up
 * video id would 404 the moment "Create Clip Project" tries to yt-dlp it. Engagement
 * numbers and published_at are randomized per call so the page looks "live" on
 * repeat visits, mirroring AbstractMockSocialProvider::fetchMetrics()'s age-curve
 * approach on the social-publishing side.
 */
abstract class AbstractMockTrendingProvider implements TrendingProvider
{
    public function isMocked(): bool
    {
        return true;
    }

    public function fetchTrending(array $filters = []): array
    {
        return array_map(function (array $seed) {
            $ageHours = random_int(1, 72);
            $views = min(5_000_000, (int) (pow($ageHours + 1, 1.7) * random_int(200, 900)));

            return new TrendingItem(
                platform: $this->platform(),
                external_id: $seed['external_id'],
                title: $seed['title'],
                source_url: $seed['source_url'],
                thumbnail_url: $seed['thumbnail_url'] ?? null,
                author_name: $seed['author_name'] ?? null,
                view_count: $views,
                like_count: (int) round($views * (random_int(4, 9) / 100)),
                comment_count: (int) round($views * (random_int(1, 3) / 1000)),
                published_at: CarbonImmutable::now()->subHours($ageHours),
                is_mock: true,
            );
        }, $this->seedItems());
    }

    /**
     * @return array<int, array{external_id: string, title: string, source_url: string, thumbnail_url?: ?string, author_name?: ?string}>
     */
    abstract protected function seedItems(): array;
}
