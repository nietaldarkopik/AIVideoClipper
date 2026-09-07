<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentIdeaSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_idea_id', 'research_result_id', 'source_key', 'source_title', 'source_url',
        'extracted_summary', 'engagement_metrics', 'source_score', 'published_at', 'discovered_at',
    ];

    protected function casts(): array
    {
        return [
            'engagement_metrics' => 'array',
            'published_at' => 'datetime',
            'discovered_at' => 'datetime',
        ];
    }

    public function idea(): BelongsTo
    {
        return $this->belongsTo(ContentIdea::class, 'content_idea_id');
    }
}
