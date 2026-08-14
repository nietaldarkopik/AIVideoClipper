<?php

namespace App\Services\AI\OpenAI;

use App\Services\AI\Contracts\TranscriptionProvider;
use App\Services\AI\DTOs\TranscriptionResult;
use App\Services\Video\FFmpegService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Real transcription via OpenAI's Whisper endpoint. Whisper auto-detects the spoken
 * language (Indonesian, English, whatever), so captions come out in the source
 * language instead of the mock provider's canned English filler.
 *
 * OpenAI caps uploads at 25MB, so long recordings are split into ~12 minute,
 * heavily compressed (48kbps mono) MP3 chunks and stitched back into one timeline.
 * Whisper doesn't return speaker diarization, so every segment is tagged speaker "A".
 */
class OpenAITranscriptionProvider implements TranscriptionProvider
{
    private const CHUNK_SECONDS = 720.0;

    public function __construct(
        private readonly FFmpegService $ffmpeg,
        private readonly string $apiKey,
        private readonly string $model = 'whisper-1',
    ) {
    }

    public function transcribe(string $audioPath, ?string $language = null): TranscriptionResult
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY is not set — required for AI_TRANSCRIPTION_PROVIDER=openai.');
        }

        $duration = $this->ffmpeg->probeDuration($audioPath) ?? 0.0;
        $tmpDir = sys_get_temp_dir() . '/clipper_whisper_' . Str::random(8);
        mkdir($tmpDir, 0775, true);

        try {
            $segments = [];
            $words = [];
            $fullTextParts = [];
            $detectedLanguage = $language;

            $offset = 0.0;
            $chunkIndex = 0;
            while ($offset < max($duration, self::CHUNK_SECONDS) && $offset < $duration) {
                $chunkLength = min(self::CHUNK_SECONDS, $duration - $offset);
                $chunkPath = "{$tmpDir}/chunk_{$chunkIndex}.mp3";
                $this->ffmpeg->transcodeAudioSegment($audioPath, $chunkPath, $offset, $chunkLength);

                $result = $this->transcribeChunk($chunkPath, $language);
                $detectedLanguage ??= $result['language'] ?? null;

                foreach ($result['segments'] as $seg) {
                    $segStart = round($offset + $seg['start'], 2);
                    $segEnd = round($offset + $seg['end'], 2);
                    $text = trim($seg['text']);
                    if ($text === '') {
                        continue;
                    }

                    $segments[] = ['start' => $segStart, 'end' => $segEnd, 'text' => $text, 'speaker' => 'A'];
                    $fullTextParts[] = $text;

                    // Whisper's segment-level timestamps are reliable; word-level ones
                    // require a second API param we skip for simplicity, so we
                    // interpolate even spacing across the segment instead.
                    $wordList = preg_split('/\s+/', $text);
                    $wordCount = max(count($wordList), 1);
                    $perWord = ($segEnd - $segStart) / $wordCount;
                    foreach ($wordList as $i => $word) {
                        $wStart = $segStart + $i * $perWord;
                        $words[] = [
                            'word' => $word,
                            'start' => round($wStart, 2),
                            'end' => round($wStart + $perWord, 2),
                            'speaker' => 'A',
                        ];
                    }
                }

                $offset += $chunkLength;
                $chunkIndex++;
            }

            return new TranscriptionResult(
                language: $detectedLanguage ?? 'unknown',
                fullText: implode(' ', $fullTextParts),
                segments: $segments,
                words: $words,
                speakers: [['id' => 'A', 'label' => 'Speaker A']],
                provider: 'openai:' . $this->model,
            );
        } finally {
            array_map('unlink', glob("{$tmpDir}/*") ?: []);
            @rmdir($tmpDir);
        }
    }

    /**
     * @return array{language: ?string, segments: array<int, array{start: float, end: float, text: string}>}
     */
    private function transcribeChunk(string $chunkPath, ?string $language): array
    {
        $request = Http::withToken($this->apiKey)
            ->timeout(300)
            ->retry(2, 2000)
            ->withOptions(['version' => 1.1])
            ->attach('file', file_get_contents($chunkPath), basename($chunkPath));

        $payload = ['model' => $this->model, 'response_format' => 'verbose_json'];
        if ($language) {
            $payload['language'] = $language;
        }

        $response = $request->post('https://api.openai.com/v1/audio/transcriptions', $payload);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI transcription failed: ' . $response->body());
        }

        $data = $response->json();

        return [
            'language' => $data['language'] ?? null,
            'segments' => array_map(fn ($s) => [
                'start' => (float) $s['start'],
                'end' => (float) $s['end'],
                'text' => (string) $s['text'],
            ], $data['segments'] ?? []),
        ];
    }
}
