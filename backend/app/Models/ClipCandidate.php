<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClipCandidate extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'video_id', 'start_time', 'end_time', 'duration',
        'overall_score', 'engagement_score', 'hook_score', 'story_score',
        'emotional_score', 'information_score', 'viral_potential',
        'hook_text', 'moment_type', 'reasons', 'explanation',
        'suggested_title', 'suggested_caption', 'suggested_hashtags', 'status',
    ];

    protected $casts = [
        'start_time' => 'float',
        'end_time' => 'float',
        'duration' => 'float',
        'reasons' => 'array',
        'suggested_hashtags' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function clip(): HasOne
    {
        return $this->hasOne(Clip::class);
    }
}
