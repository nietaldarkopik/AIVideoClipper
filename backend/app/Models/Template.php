<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Template extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_category_id', 'created_by', 'name', 'slug', 'description',
        'thumbnail_path', 'preview_path', 'preview_status', 'preview_generated_at',
        'aspect_ratio', 'resolution_width', 'resolution_height',
        'status', 'current_version_id', 'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'preview_generated_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(TemplateCategory::class, 'template_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'current_version_id');
    }

    public function clips(): HasMany
    {
        return $this->hasMany(Clip::class);
    }
}
