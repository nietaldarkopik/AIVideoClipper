<?php

namespace App\Models;

use App\Services\Video\AspectRatio;
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
        'title', 'caption', 'hashtags', 'start_time', 'end_time', 'duration', 'speed', 'volume', 'aspect_ratio',
        'crop_config', 'scenes', 'subtitle_language', 'subtitles_enabled', 'subtitle_config',
        'custom_subtitle_path', 'layer_overrides', 'segments',
        'status', 'progress', 'failure_reason', 'output_path', 'thumbnail_path',
        'output_size_bytes', 'rendered_at', 'webcam_path', 'reaction_layout',
        'reaction_script', 'reaction_tone', 'intro_enabled', 'outro_enabled',
        'intro_voice', 'intro_audio_path', 'embedding', 'embedding_model',
        'reference_url', 'intro_cover_path',
    ];

    protected $casts = [
        'hashtags' => 'array',
        'crop_config' => 'array',
        'scenes' => 'array',
        'subtitle_config' => 'array',
        'layer_overrides' => 'array',
        'segments' => 'array',
        'embedding' => 'array',
        'subtitles_enabled' => 'boolean',
        'intro_enabled' => 'boolean',
        'outro_enabled' => 'boolean',
        'start_time' => 'float',
        'end_time' => 'float',
        'duration' => 'float',
        'speed' => 'float',
        'volume' => 'float',
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

    /**
     * The actual canvas size this clip renders at: the attached template's own
     * resolution_width/height when it has one (see TemplateController — a
     * template's canvas size is a deliberate override, independent of this
     * clip's own aspect_ratio bucket, since template layers already use 0..1
     * fractional coordinates and don't care what the target resolution
     * actually is), falling back to the fixed 9:16/1:1/16:9 lookup for a clip
     * with no template. RenderClipJob and every other place that needs the
     * clip's real output dimensions should call this instead of calling
     * AspectRatio::resolution($this->aspect_ratio) directly, so a custom
     * template canvas size actually takes effect end to end.
     *
     * @return array{0: int, 1: int} [width, height]
     */
    public function targetResolution(): array
    {
        if ($this->template?->resolution_width && $this->template?->resolution_height) {
            return [$this->template->resolution_width, $this->template->resolution_height];
        }

        return AspectRatio::resolution($this->aspect_ratio);
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
