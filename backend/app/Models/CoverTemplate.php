<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoverTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'created_by', 'name', 'slug', 'description', 'thumbnail_path',
        'aspect_ratio', 'config', 'status', 'is_system',
    ];

    protected $casts = [
        'config' => 'array',
        'is_system' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function clips(): HasMany
    {
        return $this->hasMany(Clip::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class, 'default_cover_template_id');
    }
}
