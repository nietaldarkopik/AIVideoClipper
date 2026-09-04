<?php

namespace App\Services\AI\Groq;

use App\Exceptions\JobCancelledException;
use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Contracts\TranscriptionProvider;
use App\Services\AI\DTOs\TranscriptionResult;
use App\Services\Video\FFmpegService;
use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Real transcription via Groq's OpenAI-compatible /audio/transcriptions endpoint
 * (whisper-large-v3 / whisper-large-v3-turbo) — same request/response shape,
 * chunking strategy, and word-timing interpolation as OpenAITranscriptionProvider,
 * just pointed at Groq's base URL. Chosen 2026-08-30 as a free/cheap fallback when
 * both the OpenAI account behind services.openai/.nine_router ran out of credit
 * and self-hosted whisper_engine wasn't an option (CPU load on that box was
 * triggering reboots).
 */
class GroqTranscriptionProvider implements TranscriptionProvider
{
    use LogsAiRequests;

    private const CHUNK_SECONDS = 720.0;

    private const API_BASE = 'https://api.groq.com/openai/v1';

    public function __construct(
        private readonly FFmpegService $ffmpeg,
        private readonly string $apiKey,
        private readonly string $model = 'whisper-large-v3-turbo',
    ) {
    }

    public function transcribe(string $audioPath, ?string $language = null, ?Closure $shouldAbort = null): TranscriptionResult
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('GROQ_API_KEY is not set — required for AI_TRANSCRIPTION_PROVIDER=groq.');
        }

        $duration = $this->ffmpeg->probeDuration($audioPath) ?? 0.0;
        $tmpDir = sys_get_temp_dir() . '/clipper_groq_' . Str::random(8);
        mkdir($tmpDir, 0775, true);

        try {
            $segments = [];
            $words = [];
            $fullTextParts = [];
            $detectedLanguage = $language;

            $offset = 0.0;
            $chunkIndex = 0;
            while ($offset < max($duration, self::CHUNK_SECONDS) && $offset < $duration) {
                if ($shouldAbort && $shouldAbort()) {
                    throw new JobCancelledException('Cancelled by user.');
                }

                $chunkLength = min(self::CHUNK_SECONDS, $duration - $offset);
                $chunkPath = "{$tmpDir}/chunk_{$chunkIndex}.mp3";
                $this->ffmpeg->transcodeAudioSegment($audioPath, $chunkPath, $offset, $chunkLength);

                $result = $this->transcribeChunk($chunkPath, $language, $chunkLength);
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

                    // Segment-level timestamps only; interpolate even spacing across
                    // the segment for per-word timing, same tradeoff as OpenAI's.
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
                provider: 'groq:' . $this->model,
            );
        } finally {
            array_map('unlink', glob("{$tmpDir}/*") ?: []);
            @rmdir($tmpDir);
        }
    }

    /**
     * @return array{language: ?string, segments: array<int, array{start: float, end: float, text: string}>}
     */
    private function transcribeChunk(string $chunkPath, ?string $language, float $chunkLength): array
    {
        // Never log the raw audio bytes attached below — just a description of them.
        $prompt = sprintf(
            '[audio chunk: %d bytes, %.1fs] model=%s language=%s',
            filesize($chunkPath) ?: 0,
            $chunkLength,
            $this->model,
            $language ?? 'auto'
        );
        $span = $this->aiLogger()->start('transcription', 'groq', $this->model, $prompt);

        $request = Http::withToken($this->apiKey)
            ->timeout(300)
            ->retry(2, 2000)
            ->withOptions(['version' => 1.1])
            ->attach('file', file_get_contents($chunkPath), basename($chunkPath));

        $payload = ['model' => $this->model, 'response_format' => 'verbose_json'];
        if ($language) {
            $payload['language'] = $language;
        }

        $response = $request->post(self::API_BASE . '/audio/transcriptions', $payload);

        if ($response->failed()) {
            $span->failure($response->body());

            throw new RuntimeException('Groq transcription failed: ' . $response->body());
        }

        $data = $response->json();
        $span->success(json_encode(['language' => $data['language'] ?? null, 'segment_count' => count($data['segments'] ?? [])]));

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
