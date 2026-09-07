<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'research_run_id', 'content_channel_id', 'source_key', 'external_id', 'title', 'url',
        'summary', 'author', 'published_at', 'discovered_at', 'engagement', 'source_score', 'topic_key', 'raw',
    ];

    protected function casts(): array
    {
        return [
            'engagement' => 'array',
            'raw' => 'array',
            'published_at' => 'datetime',
            'discovered_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ResearchRun::class, 'research_run_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ContentChannel::class, 'content_channel_id');
    }
}
