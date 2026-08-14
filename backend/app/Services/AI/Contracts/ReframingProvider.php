<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\DTOs\ReframeKeyframe;

interface ReframingProvider
{
    /**
     * Determine smart-crop keyframes (speaker/subject tracking) for a clip so a
     * landscape source can be reframed into a target aspect ratio.
     *
     * @return ReframeKeyframe[]
     */
    public function detectCropKeyframes(
        string $videoPath,
        float $clipStart,
        float $clipEnd,
        int $sourceWidth,
        int $sourceHeight,
        string $targetAspectRatio
    ): array;
}
