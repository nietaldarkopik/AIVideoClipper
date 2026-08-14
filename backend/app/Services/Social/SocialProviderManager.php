<?php

namespace App\Services\Social;

use App\Services\Social\Contracts\SocialProvider;
use App\Services\Social\Providers\FacebookProvider;
use App\Services\Social\Providers\InstagramProvider;
use App\Services\Social\Providers\LinkedInProvider;
use App\Services\Social\Providers\TikTokProvider;
use App\Services\Social\Providers\TwitterProvider;
use App\Services\Social\Providers\YouTubeProvider;
use InvalidArgumentException;

/**
 * Resolves the adapter for a given platform key. Add new platforms by registering
 * a class here — nothing else in the app (controllers, jobs) needs to change.
 */
class SocialProviderManager
{
    /** @var array<string, class-string<SocialProvider>> */
    private array $providers = [
        'tiktok' => TikTokProvider::class,
        'youtube' => YouTubeProvider::class,
        'instagram' => InstagramProvider::class,
        'facebook' => FacebookProvider::class,
        'twitter' => TwitterProvider::class,
        'linkedin' => LinkedInProvider::class,
    ];

    public function resolve(string $platform): SocialProvider
    {
        if (! isset($this->providers[$platform])) {
            throw new InvalidArgumentException("Unknown social platform [{$platform}].");
        }

        return app($this->providers[$platform]);
    }

    /**
     * @return SocialProvider[]
     */
    public function all(): array
    {
        return array_map(fn ($class) => app($class), $this->providers);
    }

    public function supportedPlatforms(): array
    {
        return array_keys($this->providers);
    }
}
