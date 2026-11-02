<?php

namespace App\Services\AI\Support;

use App\Services\AI\Contracts\ImageGenerationProvider;

/**
 * ImageGenerationProvider that rolls over across several providers/models — see
 * ProviderChain. Which one actually produced the image is available from lastUsed().
 */
class FallbackImageGenerationProvider implements ImageGenerationProvider
{
    private readonly ProviderChain $chain;

    /**
     * @param  array<int, array{label: string, provider: ImageGenerationProvider}>  $candidates
     */
    public function __construct(array $candidates)
    {
        $this->chain = new ProviderChain('image', $candidates);
    }

    public function generateCoverImage(string $prompt, string $outputPath, ?string $fallbackFramePath = null): void
    {
        $this->chain->run(fn (ImageGenerationProvider $p) => $p->generateCoverImage($prompt, $outputPath, $fallbackFramePath));
    }

    public function lastUsed(): ?string
    {
        return $this->chain->lastUsed();
    }
}
