<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchRun extends Model
{
    use HasFactory;

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    /** Some providers failed, but the run still produced results. Not a failure. */
    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'content_channel_id', 'user_id', 'trigger', 'status', 'progress', 'message',
        'topics_found', 'results_collected', 'ideas_generated', 'duplicates_skipped',
        'providers_used', 'providers_failed', 'error_message', 'duration_ms', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'providers_used' => 'array',
            'providers_failed' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ContentChannel::class, 'content_channel_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ResearchResult::class);
    }

    public function ideas(): HasMany
    {
        return $this->hasMany(ContentIdea::class);
    }
}
