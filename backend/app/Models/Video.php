<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Video extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'source_type', 'source_url', 'original_filename', 'disk_path',
        'audio_path', 'thumbnail_path', 'thumbnail_strip_path', 'waveform_path',
        'title', 'duration_seconds', 'width', 'height',
        'file_size_bytes', 'status', 'failure_reason', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function transcript(): HasOne
    {
        return $this->hasOne(Transcript::class);
    }

    public function clipCandidates(): HasMany
    {
        return $this->hasMany(ClipCandidate::class);
    }

    public function clips(): HasMany
    {
        return $this->hasMany(Clip::class);
    }

    public function resolutionLabel(): ?string
    {
        if (! $this->width || ! $this->height) {
            return null;
        }

        return "{$this->width}x{$this->height}";
    }

    /**
     * The source channel/uploader name captured by UrlVideoDownloader at import
     * time (null for a direct file upload, or when the source didn't expose one).
     */
    public function channelName(): ?string
    {
        return $this->metadata['channel_name'] ?? null;
    }
}
