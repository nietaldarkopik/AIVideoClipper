<?php

namespace App\Services\Trending\Providers;

class MockYouTubeTrendingProvider extends AbstractMockTrendingProvider
{
    public function platform(): string
    {
        return 'youtube';
    }

    public function label(): string
    {
        return 'YouTube';
    }

    protected function seedItems(): array
    {
        return [
            [
                'external_id' => 'dQw4w9WgXcQ',
                'title' => 'Rick Astley — "Never Gonna Give You Up"',
                'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
                'author_name' => 'Rick Astley',
            ],
            [
                'external_id' => 'jNQXAC9IVRw',
                'title' => 'Me at the zoo',
                'source_url' => 'https://www.youtube.com/watch?v=jNQXAC9IVRw',
                'thumbnail_url' => 'https://i.ytimg.com/vi/jNQXAC9IVRw/hqdefault.jpg',
                'author_name' => 'jawed',
            ],
        ];
    }
}
