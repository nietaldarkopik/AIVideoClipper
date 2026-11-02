<?php

namespace App\Console\Commands;

use App\Services\AI\Contracts\TextToSpeechProvider;
use App\Services\Video\FFmpegService;
use Illuminate\Console\Command;

/**
 * Turns a text file into a speech audio file using whatever TTS provider the app is
 * configured with (9Router fallback chain by default), so external automation can
 * reuse the app's TTS without ever reading its API keys. The audio is written exactly
 * as the provider returns it (9Router returns WAV regardless of the extension).
 */
class SynthesizeSpeechFile extends Command
{
    protected $signature = 'tts:speak
        {text-file : UTF-8 text file with the narration for ONE segment}
        {out : Output audio path (e.g. narasi_s0.wav)}';

    protected $description = 'Synthesize speech for a text file with the configured TTS provider';

    public function handle(TextToSpeechProvider $tts, FFmpegService $ffmpeg): int
    {
        $textFile = (string) $this->argument('text-file');
        if (! is_file($textFile)) {
            $this->error("Text file not found: {$textFile}");

            return self::FAILURE;
        }

        $text = trim((string) file_get_contents($textFile));
        if ($text === '') {
            $this->error('Text file is empty.');

            return self::FAILURE;
        }

        $out = (string) $this->argument('out');

        try {
            $tts->synthesize($text, $out);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode([
            'success' => true,
            'file' => $out,
            'duration' => round((float) $ffmpeg->probeDuration($out), 2),
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
