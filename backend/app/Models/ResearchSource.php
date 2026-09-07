<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ResearchSource extends Model
{
    use HasFactory;

    /**
     * After this many consecutive failures a source is skipped by the engine until
     * it succeeds again from a manual "Test Connection" — the concrete form of
     * "do not repeatedly call an unavailable provider" (spec section 34).
     */
    public const FAILURE_CIRCUIT_THRESHOLD = 5;

    protected $fillable = [
        'key', 'provider', 'name', 'type', 'description', 'enabled', 'configuration',
        'last_success_at', 'last_failure_at', 'last_error', 'consecutive_failures',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'enabled' => 'boolean',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function contentChannels(): BelongsToMany
    {
        return $this->belongsToMany(ContentChannel::class, 'channel_research_sources')
            ->using(ChannelResearchSource::class)
            ->withPivot(['enabled', 'weight', 'priority', 'configuration'])
            ->withTimestamps();
    }

    public function isCircuitOpen(): bool
    {
        return $this->consecutive_failures >= self::FAILURE_CIRCUIT_THRESHOLD;
    }

    public function markSuccess(): void
    {
        $this->forceFill([
            'last_success_at' => now(),
            'consecutive_failures' => 0,
            'last_error' => null,
        ])->save();
    }

    public function markFailure(string $error): void
    {
        $this->forceFill([
            'last_failure_at' => now(),
            'last_error' => mb_substr($error, 0, 2000),
            'consecutive_failures' => $this->consecutive_failures + 1,
        ])->save();
    }
}
