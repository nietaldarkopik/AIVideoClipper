<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentIdea extends Model
{
    use HasFactory;

    public const STATUS_IDEA = 'idea';

    public const STATUS_SELECTED = 'selected';

    public const STATUS_SCRIPTING = 'scripting';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_IDEA, self::STATUS_SELECTED, self::STATUS_SCRIPTING, self::STATUS_DRAFT,
        self::STATUS_APPROVED, self::STATUS_PUBLISHED, self::STATUS_REJECTED,
    ];

    /**
     * Statuses a new idea is deduplicated against. REJECTED is included on purpose:
     * re-proposing something the user already turned down is exactly the repetition
     * the duplicate check exists to prevent.
     */
    public const DEDUPE_STATUSES = self::STATUSES;

    protected $fillable = [
        'content_channel_id', 'research_run_id', 'user_id', 'research_date',
        'topic', 'title', 'alternative_titles', 'short_description', 'content_angle',
        'why_this_topic', 'target_audience', 'keywords', 'source_summary',
        'trend_score', 'relevance_score', 'originality_score', 'freshness_score',
        'engagement_score', 'cross_source_score', 'priority_score',
        'suggested_content_type', 'suggested_format', 'status', 'notes', 'fingerprint', 'selected_at',
    ];

    protected function casts(): array
    {
        return [
            'research_date' => 'date',
            'alternative_titles' => 'array',
            'keywords' => 'array',
            'selected_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ContentChannel::class, 'content_channel_id');
    }

    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(ResearchRun::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ContentIdeaSource::class);
    }
}
