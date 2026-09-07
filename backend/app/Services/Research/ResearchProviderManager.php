<?php

namespace App\Services\Research;

use App\Services\Research\Contracts\ResearchProvider;
use InvalidArgumentException;

/**
 * Resolves a research provider by its key — same shape as
 * App\Services\Trending\TrendingProviderManager and SocialProviderManager.
 *
 * This is the ONLY place the engine learns which provider classes exist. Adding a
 * source means adding a class + one entry in ResearchServiceProvider's map + a
 * research_sources row; ChannelResearchEngine never changes.
 */
class ResearchProviderManager
{
    /**
     * @param  array<string, class-string<ResearchProvider>>  $providers
     */
    public function __construct(private readonly array $providers) {}

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    public function resolve(string $key): ResearchProvider
    {
        if (! isset($this->providers[$key])) {
            throw new InvalidArgumentException("Unknown research provider [{$key}].");
        }

        return app($this->providers[$key]);
    }

    /**
     * @return ResearchProvider[] keyed by provider key
     */
    public function all(): array
    {
        $resolved = [];

        foreach ($this->providers as $key => $class) {
            $resolved[$key] = app($class);
        }

        return $resolved;
    }

    /**
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }
}
