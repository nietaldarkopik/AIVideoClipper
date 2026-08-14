<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPost extends Model
{
    use HasFactory;

    public const STATUS_READY = 'ready';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RETRYING = 'retrying';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'clip_id', 'social_account_id', 'platform', 'title', 'caption', 'hashtags',
        'status', 'scheduled_at', 'published_at', 'post_url', 'external_post_id',
        'error_message', 'retry_count', 'metrics', 'metrics_synced_at',
    ];

    protected $casts = [
        'hashtags' => 'array',
        'metrics' => 'array',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'metrics_synced_at' => 'datetime',
    ];

    public function clip(): BelongsTo
    {
        return $this->belongsTo(Clip::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
