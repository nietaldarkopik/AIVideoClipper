<?php

namespace App\Services\AI\Mock;

use App\Services\AI\Contracts\TextToSpeechProvider;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Zero-cost placeholder: a silent mp3 whose length approximates how long the
 * given text would take to actually speak, so the intro-cover pipeline (cover
 * image + audio duration + concat) is fully exercisable with no API keys — same
 * "deterministic filler" role as every other mock provider in this app.
 */
class MockTextToSpeechProvider implements TextToSpeechProvider
{
    private const SECONDS_PER_CHAR = 0.06;

    private const MIN_SECONDS = 2.0;

    private const MAX_SECONDS = 12.0;

    public function __construct(
        private readonly string $ffmpegBin = 'ffmpeg',
    ) {
    }

    public function synthesize(string $text, string $outPath, ?string $voice = null): void
    {
        $dir = dirname($outPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $duration = max(self::MIN_SECONDS, min(self::MAX_SECONDS, mb_strlen($text) * self::SECONDS_PER_CHAR));

        $result = Process::timeout(60)->run([
            $this->ffmpegBin, '-y',
            '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono',
            '-t', (string) $duration,
            '-c:a', 'libmp3lame', '-b:a', '48k',
            $outPath,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('Mock TTS failed to generate placeholder audio: ' . $result->errorOutput());
        }
    }
}
