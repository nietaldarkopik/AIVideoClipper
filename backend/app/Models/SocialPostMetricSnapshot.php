<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day's reading of a post's cumulative platform metrics. Written by
 * SocialMetricsSyncer on every successful sync (upserted, so the last reading of
 * a day wins) and read back by AnalyticsController to build day-over-day trends.
 */
class SocialPostMetricSnapshot extends Model
{
    protected $fillable = [
        'social_post_id', 'captured_on', 'views', 'likes', 'comments', 'shares', 'saves',
    ];

    protected $casts = [
        'captured_on' => 'date',
        'views' => 'integer',
        'likes' => 'integer',
        'comments' => 'integer',
        'shares' => 'integer',
        'saves' => 'integer',
    ];

    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }
}
