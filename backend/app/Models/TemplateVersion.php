<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TemplateVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_id', 'version_number', 'label', 'is_published', 'created_by', 'config',
    ];

    protected $casts = [
        'config' => 'array',
        'is_published' => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function clips(): HasMany
    {
        return $this->hasMany(Clip::class);
    }
}
