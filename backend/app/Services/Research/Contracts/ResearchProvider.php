<?php

namespace App\Services\Research\Contracts;

use App\Services\Research\DTOs\ProviderHealth;
use App\Services\Research\DTOs\ResearchItem;
use App\Services\Research\DTOs\ResearchQuery;

/**
 * One research source. Implement this + register the class in
 * ResearchServiceProvider's map and seed a research_sources row — that is the
 * whole contract for adding a source. The engine (ChannelResearchEngine) never
 * names a provider, so it needs no change.
 *
 * Implementations must be independent of each other and of any channel: they read
 * only the ResearchQuery handed to them.
 */
interface ResearchProvider
{
    /** Stable machine key, matching the research_sources.provider column. */
    public function key(): string;

    public function label(): string;

    /** trends | social | news | code | video | media | gaming | general */
    public function type(): string;

    /**
     * False when this provider needs no API credentials at all. Used by the UI to
     * show which sources work out of the box.
     */
    public function requiresCredentials(): bool;

    /**
     * Whether required credentials are actually present. An unconfigured provider is
     * SKIPPED with a clear reason, never called and never reported as a failure —
     * otherwise a missing optional key would trip the failure circuit breaker.
     */
    public function isConfigured(): bool;

    /**
     * Keyword-driven retrieval. Must return [] rather than throw when the source
     * legitimately has no matches; throw only on a real transport/API error, which
     * the engine records against this source alone.
     *
     * @return ResearchItem[]
     */
    public function search(ResearchQuery $query): array;

    /**
     * Broad "what's hot right now" retrieval, ignoring keywords where the source
     * supports it. Providers with no such concept return [].
     *
     * @return ResearchItem[]
     */
    public function trending(ResearchQuery $query): array;

    public function healthCheck(): ProviderHealth;

    /**
     * Describes the per-channel configuration fields this provider understands, so
     * the settings UI can render them without knowing anything about the provider.
     *
     * @return array<int, array{key: string, label: string, type: string, help?: string, default?: mixed}>
     */
    public function configSchema(): array;
}
