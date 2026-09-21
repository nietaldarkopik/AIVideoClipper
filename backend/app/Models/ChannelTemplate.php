<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChannelTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'name', 'description', 'platform_key', 'defaults', 'enabled', 'sort_order'];

    protected function casts(): array
    {
        return [
            'defaults' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
