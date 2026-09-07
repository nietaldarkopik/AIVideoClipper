<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentChannel extends Model
{
    use HasFactory;

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_TWICE_DAILY = 'twice_daily';

    public const FREQUENCY_EVERY_N_HOURS = 'every_n_hours';

    public const FREQUENCY_CUSTOM = 'custom';

    public const FREQUENCIES = [
        self::FREQUENCY_DAILY, self::FREQUENCY_TWICE_DAILY,
        self::FREQUENCY_EVERY_N_HOURS, self::FREQUENCY_CUSTOM,
    ];

    protected $fillable = [
        'user_id', 'platform_id', 'name', 'handle', 'description', 'language', 'timezone', 'is_active',
        'niche', 'sub_niches', 'keywords', 'excluded_keywords', 'target_audience',
        'content_style', 'content_types', 'content_formats', 'tone', 'hook_styles',
        'scheduler_enabled', 'research_frequency', 'research_times', 'interval_hours',
        'ideas_per_run', 'min_relevance_score', 'min_trend_score', 'scoring_weights',
        'last_research_at', 'next_research_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'scheduler_enabled' => 'boolean',
            'sub_niches' => 'array',
            'keywords' => 'array',
            'excluded_keywords' => 'array',
            'content_style' => 'array',
            'content_types' => 'array',
            'content_formats' => 'array',
            'hook_styles' => 'array',
            'research_times' => 'array',
            'scoring_weights' => 'array',
            'last_research_at' => 'datetime',
            'next_research_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function researchSources(): BelongsToMany
    {
        return $this->belongsToMany(ResearchSource::class, 'channel_research_sources')
            ->using(ChannelResearchSource::class)
            ->withPivot(['id', 'enabled', 'weight', 'priority', 'configuration'])
            ->withTimestamps();
    }

    public function researchRuns(): HasMany
    {
        return $this->hasMany(ResearchRun::class);
    }

    public function contentIdeas(): HasMany
    {
        return $this->hasMany(ContentIdea::class);
    }

    /**
     * The times of day this channel researches, in its OWN timezone.
     *
     * research_times is authoritative for every frequency; `every_n_hours` is
     * expanded here rather than stored so changing interval_hours takes effect
     * without a backfill. Keeping one representation means the due check below
     * has no per-frequency branch.
     *
     * @return string[] e.g. ["06:00", "12:00"]
     */
    public function scheduleTimes(): array
    {
        if ($this->research_frequency === self::FREQUENCY_EVERY_N_HOURS) {
            $interval = max(1, (int) ($this->interval_hours ?: 6));
            $times = [];
            for ($hour = 0; $hour < 24; $hour += $interval) {
                $times[] = sprintf('%02d:00', $hour);
            }

            return $times;
        }

        $times = array_values(array_filter(
            array_map(fn ($t) => is_string($t) ? trim($t) : null, $this->research_times ?? []),
            fn ($t) => is_string($t) && preg_match('/^\d{2}:\d{2}$/', $t) === 1,
        ));

        if ($times !== []) {
            sort($times);

            return $times;
        }

        return match ($this->research_frequency) {
            self::FREQUENCY_TWICE_DAILY => ['06:00', '18:00'],
            default => ['06:00'],
        };
    }

    /**
     * Next scheduled moment strictly after $after, as UTC.
     *
     * Computed in the channel's own timezone then converted, so a channel set to
     * "06:00 Asia/Jakarta" keeps firing at local 06:00 across DST changes in other
     * zones rather than drifting with the server's clock.
     */
    public function nextRunAfter(?CarbonImmutable $after = null): CarbonImmutable
    {
        $tz = $this->timezone ?: config('app.timezone');
        $after = ($after ?? CarbonImmutable::now())->setTimezone($tz);
        $times = $this->scheduleTimes();

        // Today's remaining slots first, then tomorrow's earliest — never "now + interval",
        // which would let a manual run at 05:59 silently push the 06:00 slot to the next day.
        foreach ([0, 1] as $dayOffset) {
            $day = $after->addDays($dayOffset)->startOfDay();
            foreach ($times as $time) {
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $candidate = $day->setTime($hour, $minute);
                if ($candidate->greaterThan($after)) {
                    return $candidate->utc();
                }
            }
        }

        return $after->addDay()->startOfDay()->utc();
    }

    public function isDue(?CarbonImmutable $now = null): bool
    {
        if (! $this->is_active || ! $this->scheduler_enabled) {
            return false;
        }

        $now = $now ?? CarbonImmutable::now();

        // A channel whose scheduler was only just enabled has no next_research_at yet.
        // Treat that as "not due" and let the scheduler stamp it, so enabling a channel
        // at 23:50 doesn't immediately fire an unscheduled run.
        if ($this->next_research_at === null) {
            return false;
        }

        return CarbonImmutable::instance($this->next_research_at)->lessThanOrEqualTo($now);
    }

    /**
     * Weights for the priority score, channel override first, then the global config.
     * Unknown keys in a stored override are ignored rather than trusted, so an old
     * saved payload can't introduce a weight the scorer doesn't know about.
     *
     * @return array<string, float>
     */
    public function effectiveScoringWeights(): array
    {
        $defaults = config('research.scoring.weights');
        $override = $this->scoring_weights;

        if (! is_array($override) || $override === []) {
            return $defaults;
        }

        $merged = $defaults;
        foreach ($defaults as $key => $_) {
            if (isset($override[$key]) && is_numeric($override[$key])) {
                $merged[$key] = (float) $override[$key];
            }
        }

        return $merged;
    }
}
