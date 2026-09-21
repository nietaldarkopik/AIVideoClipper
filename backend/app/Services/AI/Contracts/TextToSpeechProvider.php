<?php

namespace App\Services\AI\Contracts;

interface TextToSpeechProvider
{
    /**
     * Synthesize $text to speech and write it as an mp3 file at $outPath. Duration
     * isn't returned here — callers read it back via FFmpegService::probeDuration()
     * once the file exists, same as every other generated media asset in this app.
     */
    public function synthesize(string $text, string $outPath, ?string $voice = null): void;
}
