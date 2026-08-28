<?php

namespace App\Services\AI\Mock;

use App\Services\AI\Contracts\EmbeddingProvider;

/**
 * Zero-cost placeholder: a deterministic pseudo-vector hashed from the input text,
 * with no network call — same "deterministic filler" role as every other mock
 * provider in this app (see MockTextToSpeechProvider). Unlike a real embedding,
 * this carries no actual semantic meaning — two unrelated texts that happen to
 * hash similarly will rank as "similar," and vice versa. It exists so
 * ClipSearchService/GenerateClipEmbeddingJob are fully exercisable with zero API
 * keys, not to produce a useful search ranking; set AI_EMBEDDING_PROVIDER=nine_router
 * for real semantic search.
 */
class MockEmbeddingProvider implements EmbeddingProvider
{
    private const DIMENSIONS = 32;

    public function embed(string $text): array
    {
        // sha256 gives 64 hex chars = 32 bytes, exactly DIMENSIONS 2-char chunks —
        // no wraparound/reuse needed, unlike a shorter hash.
        $hash = hash('sha256', $text);
        $vector = [];

        for ($i = 0; $i < self::DIMENSIONS; $i++) {
            $byte = hexdec(substr($hash, $i * 2, 2));
            $vector[] = ($byte / 255.0) * 2 - 1;
        }

        return $vector;
    }
}
