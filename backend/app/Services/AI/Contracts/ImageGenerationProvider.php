<?php

namespace App\Services\AI\Contracts;

interface ImageGenerationProvider
{
    /**
     * Produce a cover-image file at $outputPath for the reaction intro — see
     * RenderClipJob::composeIntroOutro().
     *
     * $fallbackFramePath, if given, is a real frame already extracted from the
     * clip's own video (cheap, always available) — a provider with no real
     * image-generation backing (Mock) can just reuse it as-is instead of calling
     * out anywhere, which is also today's pre-this-feature default behavior.
     */
    public function generateCoverImage(string $prompt, string $outputPath, ?string $fallbackFramePath = null): void;
}
