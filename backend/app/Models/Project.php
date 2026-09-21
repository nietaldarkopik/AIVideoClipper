<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    /**
     * Where a project came from, so a published clip's performance can be traced
     * back to what suggested it (see the origin columns migration). ORIGIN_TRENDING
     * carries no origin_id — trending items are fetched live from each platform and
     * have no local row to reference.
     */
    public const ORIGIN_CONTENT_IDEA = 'content_idea';

    public const ORIGIN_CONTENT_BRIEF = 'content_brief';

    public const ORIGIN_TRENDING = 'trending';

    public const ORIGINS = [self::ORIGIN_CONTENT_IDEA, self::ORIGIN_CONTENT_BRIEF, self::ORIGIN_TRENDING];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_TRANSCRIBING = 'transcribing';
    public const STATUS_ANALYZING = 'analyzing';
    public const STATUS_GENERATING_CLIPS = 'generating_clips';
    public const STATUS_RENDERING = 'rendering';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id', 'title', 'description', 'origin_type', 'origin_id', 'status', 'failure_reason', 'last_edited_at',
    ];

    protected $casts = [
        'last_edited_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function clipCandidates(): HasMany
    {
        return $this->hasMany(ClipCandidate::class);
    }

    public function clips(): HasMany
    {
        return $this->hasMany(Clip::class);
    }

    public function processingJobs(): HasMany
    {
        return $this->hasMany(ProcessingJob::class);
    }

    public function primaryVideo(): ?Video
    {
        return $this->videos()->latest()->first();
    }
}
