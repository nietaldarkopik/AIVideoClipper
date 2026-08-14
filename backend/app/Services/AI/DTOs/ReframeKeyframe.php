<?php

namespace App\Services\AI\DTOs;

class ReframeKeyframe
{
    public function __construct(
        public readonly float $time,
        public readonly float $x,
        public readonly float $y,
        public readonly float $width,
        public readonly float $height,
        public readonly ?string $activeSpeaker = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'time' => $this->time,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'active_speaker' => $this->activeSpeaker,
        ];
    }
}
