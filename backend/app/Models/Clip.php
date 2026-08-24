<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Clip extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RENDERING = 'rendering';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'project_id', 'video_id', 'clip_candidate_id', 'template_id', 'template_version_id',
        'title', 'caption', 'hashtags', 'start_time', 'end_time', 'duration', 'aspect_ratio',
        'crop_config', 'scenes', 'subtitle_language', 'subtitles_enabled', 'subtitle_config',
        'status', 'progress', 'failure_reason', 'output_path', 'thumbnail_path',
        'output_size_bytes', 'rendered_at', 'webcam_path', 'reaction_layout',
    ];

    protected $casts = [
        'hashtags' => 'array',
        'crop_config' => 'array',
        'scenes' => 'array',
        'subtitle_config' => 'array',
        'subtitles_enabled' => 'boolean',
        'start_time' => 'float',
        'end_time' => 'float',
        'duration' => 'float',
        'rendered_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function clipCandidate(): BelongsTo
    {
        return $this->belongsTo(ClipCandidate::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class);
    }

    public function subtitle(): HasOne
    {
        return $this->hasOne(Subtitle::class)->where('language', $this->subtitle_language ?? 'en');
    }

    public function subtitles(): HasMany
    {
        return $this->hasMany(Subtitle::class);
    }

    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }
}
