<?php

namespace App\Http\Resources\Research;

use App\Models\ResearchSource;
use App\Services\Research\ResearchProviderManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Note what is absent: no API key, token or secret ever appears here. Credentials
 * live in config (env-backed) and this endpoint only reports WHETHER a provider is
 * configured, never with what (spec section 35).
 */
class ResearchSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $manager = app(ResearchProviderManager::class);
        $registered = $manager->has($this->provider);
        $provider = $registered ? $manager->resolve($this->provider) : null;

        return [
            'id' => $this->id,
            'key' => $this->key,
            'provider' => $this->provider,
            'name' => $this->name,
            'type' => $this->type,
            'description' => $this->description,
            'enabled' => $this->enabled,
            'configuration' => $this->configuration ?? [],
            'registered' => $registered,
            'requires_credentials' => $provider?->requiresCredentials() ?? false,
            'is_configured' => $provider?->isConfigured() ?? false,
            'config_schema' => $provider?->configSchema() ?? [],
            'health' => [
                'last_success_at' => $this->last_success_at,
                'last_failure_at' => $this->last_failure_at,
                'last_error' => $this->last_error,
                'consecutive_failures' => $this->consecutive_failures,
                // Surfaced explicitly so the UI can explain WHY a source stopped running,
                // rather than the user seeing it silently absent from every run.
                'circuit_open' => $this->resource instanceof ResearchSource && $this->resource->isCircuitOpen(),
            ],
            // Only present when loaded through a channel's pivot.
            'pivot' => $this->whenPivotLoaded('channel_research_sources', fn () => [
                'enabled' => (bool) $this->pivot->enabled,
                'weight' => (float) $this->pivot->weight,
                'priority' => (int) $this->pivot->priority,
                'configuration' => $this->pivot->configuration ?? [],
            ]),
        ];
    }
}
