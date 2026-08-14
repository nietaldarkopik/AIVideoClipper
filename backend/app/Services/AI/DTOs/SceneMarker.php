<?php

namespace App\Services\AI\DTOs;

class SceneMarker
{
    public function __construct(
        public readonly float $time,
        public readonly string $type = 'cut', // cut, fade
    ) {
    }

    public function toArray(): array
    {
        return ['time' => $this->time, 'type' => $this->type];
    }
}
