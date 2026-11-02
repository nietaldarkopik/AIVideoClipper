<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\DTOs\StoryboardResult;

interface StoryboardProvider
{
    /**
     * Turn research material into a short illustrated cartoon explainer: condense it to
     * a ~45-60 second narration and split that into scenes with a visual for each.
     *
     * $material is whatever the source had — a long-form brief script or just an idea's
     * summary — so the provider must condense/expand it into short-form narration
     * rather than assume it fits. It must not invent facts that aren't in the material.
     * $maxScenes caps the scene count because every scene costs an image, a TTS call and
     * (optionally) a video-model call.
     */
    public function generateStoryboard(string $topic, string $material, int $maxScenes): StoryboardResult;
}
