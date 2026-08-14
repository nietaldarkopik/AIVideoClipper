<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transcript extends Model
{
    use HasFactory;

    protected $fillable = [
        'video_id', 'language', 'full_text', 'segments', 'words', 'speakers', 'provider',
    ];

    protected $casts = [
        'segments' => 'array',
        'words' => 'array',
        'speakers' => 'array',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
