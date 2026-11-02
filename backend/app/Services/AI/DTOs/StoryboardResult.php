<?php

namespace App\Services\AI\DTOs;

class StoryboardResult
{
    /**
     * @param  array<int, array{narration: string, visual_prompt: string, motion_prompt: string}>  $scenes
     */
    public function __construct(
        public readonly string $character_description,
        public readonly array $scenes,
    ) {
    }
}
