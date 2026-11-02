<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartoonScene extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'cartoon_video_id', 'position', 'narration', 'visual_prompt', 'motion_prompt',
        'image_path', 'audio_path', 'clip_path', 'image_provider', 'clip_provider', 'audio_duration', 'status', 'error',
    ];

    protected function casts(): array
    {
        return ['audio_duration' => 'float'];
    }

    public function cartoonVideo(): BelongsTo
    {
        return $this->belongsTo(CartoonVideo::class);
    }
}
