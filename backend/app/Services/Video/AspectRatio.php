<?php

namespace App\Services\Video;

class AspectRatio
{
    /**
     * @return array{0: int, 1: int} [width, height]
     */
    public static function resolution(string $aspectRatio): array
    {
        return match ($aspectRatio) {
            '1:1' => [1080, 1080],
            '16:9' => [1920, 1080],
            default => [1080, 1920], // 9:16
        };
    }
}
