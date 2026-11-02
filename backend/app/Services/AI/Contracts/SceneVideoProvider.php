<?php

namespace App\Services\AI\Contracts;

interface SceneVideoProvider
{
    /**
     * Turn one still cartoon image into a short vertical video clip at $outPath.
     *
     * $targetSeconds is how long the scene's narration runs. A provider that controls
     * clip length (camera motion) should produce about that long; one that can't
     * (an AI video model returns whatever length it returns) may produce more or
     * less — CartoonVideoAssembler trims or holds the last frame to fit, so callers
     * must not assume the result matches $targetSeconds exactly.
     */
    public function animate(string $imagePath, string $motionPrompt, string $outPath, float $targetSeconds): void;
}
