<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentBrief extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SEARCHING = 'searching';

    public const STATUS_FETCHING_SOURCES = 'fetching_sources';

    public const STATUS_GENERATING_SCRIPT = 'generating_script';

    public const STATUS_FINDING_VIDEOS = 'finding_videos';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING, self::STATUS_SEARCHING, self::STATUS_FETCHING_SOURCES,
        self::STATUS_GENERATING_SCRIPT, self::STATUS_FINDING_VIDEOS,
    ];

    protected $fillable = [
        'user_id', 'topic', 'region_code', 'source_platform', 'source_trending_title', 'source_trending_url',
        'status', 'progress', 'message', 'failure_reason', 'cancel_requested',
        'sources', 'candidate_videos',
        'narrative_title', 'narrative_hook', 'narrative_sections', 'narrative_full_script',
        'narrative_suggested_description', 'narrative_suggested_hashtags',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'cancel_requested' => 'boolean',
            'sources' => 'array',
            'candidate_videos' => 'array',
            'narrative_sections' => 'array',
            'narrative_suggested_hashtags' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
