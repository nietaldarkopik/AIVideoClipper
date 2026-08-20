<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoBatchItem extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTING = 'importing';
    public const STATUS_ANALYZING = 'analyzing';
    public const STATUS_RENDERING = 'rendering';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_CANCELLED = 'cancelled';

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_SKIPPED, self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'video_batch_id', 'position', 'source_url', 'project_id', 'status', 'progress',
        'message', 'failure_reason', 'clips_generated', 'posts_published', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function videoBatch(): BelongsTo
    {
        return $this->belongsTo(VideoBatch::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
