<?php

namespace App\Services\AI\DTOs;

class VideoNarrativeSection
{
    public function __construct(
        public readonly string $heading,
        public readonly string $narration_text,
        public readonly int $duration_estimate_seconds,
    ) {
    }
}
