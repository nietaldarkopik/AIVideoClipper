<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRequestLog extends Model
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'capability', 'provider', 'model', 'project_id', 'video_id', 'clip_id',
        'prompt', 'response', 'status', 'error_message', 'duration_ms',
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
}
