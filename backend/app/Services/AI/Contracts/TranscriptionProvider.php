<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\DTOs\TranscriptionResult;
use Closure;

interface TranscriptionProvider
{
    /**
     * Transcribe the audio track at $audioPath into timestamped segments and words.
     *
     * $shouldAbort, if given, is checked between internal chunks by providers that
     * split long audio into several requests (see WhisperEngineTranscriptionProvider)
     * so a caller (AnalyzeVideoJob) can stop a multi-minute transcription partway
     * through instead of only between its own top-level phases. Providers that make
     * a single request may ignore it. Returning true throws
     * App\Exceptions\JobCancelledException.
     */
    public function transcribe(string $audioPath, ?string $language = null, ?Closure $shouldAbort = null): TranscriptionResult;
}
