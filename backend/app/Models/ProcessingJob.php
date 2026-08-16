<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessingJob extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'project_id', 'video_id', 'clip_id', 'type', 'status', 'progress',
        'message', 'error', 'attempts', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function clip(): BelongsTo
    {
        return $this->belongsTo(Clip::class);
    }

    public function markRunning(?string $message = null): void
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'message' => $message ?? $this->message,
            'started_at' => $this->started_at ?? now(),
        ]);
    }

    public function markProgress(int $progress, ?string $message = null): void
    {
        $this->update(array_filter([
            'progress' => $progress,
            'message' => $message,
        ], fn ($v) => $v !== null));
    }

    public function markCompleted(?string $message = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'progress' => 100,
            'message' => $message ?? $this->message,
            'finished_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error' => $error,
            'finished_at' => now(),
        ]);
    }

    /**
     * Flag this job as cancelled. Doesn't stop the PHP process actually running it
     * (impossible without pcntl on Windows) — the running job's own cooperative
     * check (App\Jobs\Concerns\ChecksCancellation) is what notices this and bails.
     */
    public function cancel(?string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'error' => $reason ?? 'Cancelled by user.',
            'finished_at' => now(),
        ]);
    }
}
