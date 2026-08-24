<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelWatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'platform', 'channel_id', 'channel_title', 'channel_url', 'thumbnail_url', 'uploads_playlist_id',
        'is_active', 'settings', 'last_video_id', 'last_video_published_at', 'last_checked_at', 'last_error',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'last_video_published_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
