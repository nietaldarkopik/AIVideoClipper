<?php

namespace App\Services\AI\DTOs;

class TranscriptionResult
{
    /**
     * @param  array<int, array{start: float, end: float, text: string, speaker: string}>  $segments
     * @param  array<int, array{word: string, start: float, end: float, speaker: string}>  $words
     * @param  array<int, array{id: string, label: string}>  $speakers
     */
    public function __construct(
        public readonly string $language,
        public readonly string $fullText,
        public readonly array $segments,
        public readonly array $words,
        public readonly array $speakers,
        public readonly string $provider,
    ) {
    }

    public function toArray(): array
    {
        return [
            'language' => $this->language,
            'full_text' => $this->fullText,
            'segments' => $this->segments,
            'words' => $this->words,
            'speakers' => $this->speakers,
            'provider' => $this->provider,
        ];
    }
}
