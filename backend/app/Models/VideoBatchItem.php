<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * The ProcessingJob rows of this item's project, joined on project_id rather
     * than through a foreign key of their own (an item owns exactly one project —
     * see BatchItemDownloader — so its project's jobs are its jobs).
     *
     * Read-only, and deliberately so: it exists purely so the batch UI can show
     * the live download progress ImportVideoJob already writes to ProcessingJob
     * (percentage, speed, ETA) instead of the coarse "Downloading video..." this
     * item's own message column is left on for the whole download. Nothing in the
     * import path needs to know a batch item exists, which is what keeps that path
     * free of a progress callback it would have to carry through the queue.
     */
    public function processingJobs(): HasMany
    {
        return $this->hasMany(ProcessingJob::class, 'project_id', 'project_id');
    }
}
