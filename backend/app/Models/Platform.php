<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Platform extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'name', 'type', 'default_strategy', 'enabled', 'sort_order'];

    protected function casts(): array
    {
        return [
            'default_strategy' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function contentChannels(): HasMany
    {
        return $this->hasMany(ContentChannel::class);
    }
}
