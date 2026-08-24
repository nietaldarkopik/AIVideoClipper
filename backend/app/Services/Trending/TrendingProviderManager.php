<?php

namespace App\Services\Trending;

use App\Services\Trending\Contracts\TrendingProvider;
use InvalidArgumentException;

/**
 * Resolves the adapter for a given platform key, same shape as
 * App\Services\Social\SocialProviderManager. Unlike that manager, the map is
 * constructor-injected rather than hardcoded — YouTube's entry is chosen
 * conditionally by env at bind time (see TrendingServiceProvider).
 */
class TrendingProviderManager
{
    /**
     * @param  array<string, class-string<TrendingProvider>>  $providers
     */
    public function __construct(private readonly array $providers)
    {
    }

    public function resolve(string $platform): TrendingProvider
    {
        if (! isset($this->providers[$platform])) {
            throw new InvalidArgumentException("Unknown trending platform [{$platform}].");
        }

        return app($this->providers[$platform]);
    }

    /**
     * @return TrendingProvider[]
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
