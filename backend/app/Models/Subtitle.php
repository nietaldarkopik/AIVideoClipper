<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subtitle extends Model
{
    use HasFactory;

    protected $fillable = ['clip_id', 'language', 'segments', 'srt_path', 'ass_path'];

    protected $casts = [
        'segments' => 'array',
    ];

    public function clip(): BelongsTo
    {
        return $this->belongsTo(Clip::class);
    }
}
