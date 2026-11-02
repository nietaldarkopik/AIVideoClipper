<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cartoon-illustrated explainer short made from research output (a ContentIdea or
 * a ContentBrief). Lifecycle: pending -> storyboarding -> storyboard_ready (the user
 * reviews/edits scenes) -> rendering (one job per scene) -> assembling -> completed.
 * needs_attention means every scene job finished but some failed; re-rendering only
 * re-runs the scenes that aren't done yet.
 */
class CartoonVideo extends Model
{
    use HasFactory;

    public const SOURCE_CONTENT_IDEA = 'content_idea';

    public const SOURCE_CONTENT_BRIEF = 'content_brief';

    public const SOURCES = [self::SOURCE_CONTENT_IDEA, self::SOURCE_CONTENT_BRIEF];

    public const STATUS_PENDING = 'pending';

    public const STATUS_STORYBOARDING = 'storyboarding';

    public const STATUS_STORYBOARD_READY = 'storyboard_ready';

    public const STATUS_RENDERING = 'rendering';

    public const STATUS_ASSEMBLING = 'assembling';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NEEDS_ATTENTION = 'needs_attention';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING, self::STATUS_STORYBOARDING, self::STATUS_RENDERING, self::STATUS_ASSEMBLING,
    ];

    protected $fillable = [
        'user_id', 'source_type', 'source_id', 'title', 'topic', 'character_description',
        'style', 'voice', 'motion_provider', 'status', 'progress', 'message', 'failure_reason',
        'cancel_requested', 'output_path', 'thumbnail_path', 'duration_seconds',
        'project_id', 'clip_id', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'cancel_requested' => 'boolean',
            'duration_seconds' => 'float',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(CartoonScene::class)->orderBy('position');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function clip(): BelongsTo
    {
        return $this->belongsTo(Clip::class);
    }
}
