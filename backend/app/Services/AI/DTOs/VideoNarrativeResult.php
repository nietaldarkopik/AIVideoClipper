<?php

namespace App\Services\AI\DTOs;

class VideoNarrativeResult
{
    /**
     * @param  VideoNarrativeSection[]  $sections
     * @param  string[]  $suggested_hashtags
     */
    public function __construct(
        public readonly string $title,
        public readonly string $hook,
        public readonly array $sections,
        public readonly string $full_script,
        public readonly string $suggested_description,
        public readonly array $suggested_hashtags,
    ) {
    }
}
