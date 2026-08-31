<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\DTOs\VideoNarrativeResult;

interface VideoNarrativeProvider
{
    /**
     * Generate an original, plagiarism-free, Indonesian-language long-form video
     * narrative/script for $topic, synthesized from research already gathered in
     * $sources — never copied/closely paraphrased from any single source.
     *
     * @param  array<int, array{title: string, url: string, content: string}>  $sources
     */
    public function generateNarrative(string $topic, array $sources): VideoNarrativeResult;
}
