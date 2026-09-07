<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Custom Pivot (not the default) purely so `configuration` casts to an array on
 * the pivot object — accessing it as raw JSON at the call site was the previous
 * source of "why is my subreddit list a string" confusion.
 */
class ChannelResearchSource extends Pivot
{
    protected $table = 'channel_research_sources';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'weight' => 'float',
            'configuration' => 'array',
        ];
    }
}
