<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\DTOs\TranscriptionResult;

interface TranscriptionProvider
{
    /**
     * Transcribe the audio track at $audioPath into timestamped segments and words.
     */
    public function transcribe(string $audioPath, ?string $language = null): TranscriptionResult;
}
