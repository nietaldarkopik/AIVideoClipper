<?php

namespace App\Services\AI\Contracts;

interface EmbeddingProvider
{
    /**
     * Embed $text into a vector for semantic similarity search — see
     * GenerateClipEmbeddingJob and ClipSearchService.
     *
     * @return float[]
     */
    public function embed(string $text): array;
}
