<?php

namespace App\Services\AI\WhisperEngine;

use App\Exceptions\JobCancelledException;
use App\Services\AI\Contracts\TranscriptionProvider;
use App\Services\AI\DTOs\TranscriptionResult;
use App\Services\Video\FFmpegService;
use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Real transcription via our own local faster-whisper service (tools/whisper-engine),
 * a small FastAPI process that keeps the model loaded and returns word-level
 * timestamps directly — no OpenAI API key, no per-minute cost, no 25MB upload cap.
 *
 * faster-whisper doesn't do speaker diarization, so every segment/word is tagged
 * speaker "A", same convention as OpenAITranscriptionProvider.
 *
 * Long recordings are split into fixed-length WAV chunks before each is sent to the
 * engine: transcribing an hour of audio in one call keeps the whole thing (plus
 * beam search + word-timestamp state) resident in RAM/CPU for the entire run, which
 * on modest hardware has been enough to lock up or reboot the machine. Chunking
 * bounds peak resource use per request regardless of total video length.
 *
 * Each chunk's transcription result is also cached to disk next to the source audio
 * as it completes. If the process is killed mid-run (crash, PC restart, a user
 * cancelling the job) and transcribe() is called again for the same audio file, it
 * picks up from the first chunk that isn't cached yet instead of redoing chunks
 * that already finished. The cache is deleted once a run finishes all chunks.
 */
class WhisperEngineTranscriptionProvider implements TranscriptionProvider
{
    public function __construct(
        private readonly FFmpegService $ffmpeg,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 1200,
        private readonly float $chunkSeconds = 120.0,
    ) {
    }

    public function transcribe(string $audioPath, ?string $language = null, ?Closure $shouldAbort = null): TranscriptionResult
    {
        $duration = $this->ffmpeg->probeDuration($audioPath) ?? 0.0;

        // Short clips don't need the split/stitch overhead — send as-is.
        if ($duration <= 0.0 || $duration <= $this->chunkSeconds) {
            $result = $this->transcribeChunk($audioPath, $language);

            return $this->toTranscriptionResult($result['segments'], $result['words'], $language ?? $result['language']);
        }

        $tmpDir = sys_get_temp_dir() . '/clipper_whisper_' . Str::random(8);
        mkdir($tmpDir, 0775, true);

        $cacheDir = dirname($audioPath) . '/whisper_cache';
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }
        $chunkSecondsKey = (int) round($this->chunkSeconds);

        try {
            $segments = [];
            $words = [];
            $detectedLanguage = $language;

            $offset = 0.0;
            $chunkIndex = 0;
            while ($offset < $duration) {
                if ($shouldAbort && $shouldAbort()) {
                    // Chunks transcribed so far stay cached on disk; the next
                    // transcribe() call for this audio file resumes right here.
                    throw new JobCancelledException('Cancelled by user.');
                }

                $chunkLength = min($this->chunkSeconds, $duration - $offset);
                $cachePath = "{$cacheDir}/chunk_{$chunkSecondsKey}_{$chunkIndex}.json";

                $cached = is_file($cachePath) ? json_decode((string) file_get_contents($cachePath), true) : null;
                if (is_array($cached)) {
                    $result = $cached;
                } else {
                    $chunkPath = "{$tmpDir}/chunk_{$chunkIndex}.wav";
                    $this->ffmpeg->sliceAudioSegment($audioPath, $chunkPath, $offset, $chunkLength);
                    $result = $this->transcribeChunk($chunkPath, $language);
                    file_put_contents($cachePath, json_encode($result));
                }

                $detectedLanguage ??= $result['language'];

                foreach ($result['segments'] as $seg) {
                    $segments[] = [
                        'start' => round($offset + $seg['start'], 2),
                        'end' => round($offset + $seg['end'], 2),
                        'text' => $seg['text'],
                        'speaker' => 'A',
                    ];
                }

                foreach ($result['words'] as $word) {
                    $words[] = [
                        'word' => $word['word'],
                        'start' => round($offset + $word['start'], 2),
                        'end' => round($offset + $word['end'], 2),
                        'speaker' => 'A',
                    ];
                }

                $offset += $chunkLength;
                $chunkIndex++;
            }

            $result = $this->toTranscriptionResult($segments, $words, $detectedLanguage);

            // Only reached once every chunk above succeeded — safe to drop the cache.
            array_map('unlink', glob("{$cacheDir}/*") ?: []);
            @rmdir($cacheDir);

            return $result;
        } finally {
            array_map('unlink', glob("{$tmpDir}/*") ?: []);
            @rmdir($tmpDir);
        }
    }

    /**
     * @param  array<int, array{start: float, end: float, text: string, speaker: string}>  $segments
     * @param  array<int, array{word: string, start: float, end: float, speaker: string}>  $words
     */
    private function toTranscriptionResult(array $segments, array $words, ?string $language): TranscriptionResult
    {
        return new TranscriptionResult(
            language: $language ?? 'unknown',
            fullText: implode(' ', array_column($segments, 'text')),
            segments: $segments,
            words: $words,
            speakers: [['id' => 'A', 'label' => 'Speaker A']],
            provider: 'whisper_engine',
        );
    }

    /**
     * @return array{language: ?string, segments: array<int, array{start: float, end: float, text: string}>, words: array<int, array{word: string, start: float, end: float}>}
     */
    private function transcribeChunk(string $audioPath, ?string $language): array
    {
        $response = Http::timeout($this->timeoutSeconds)
            ->post(rtrim($this->baseUrl, '/') . '/transcribe', array_filter([
                'audio_path' => $audioPath,
                'language' => $language,
            ]));

        if ($response->failed()) {
            throw new RuntimeException(
                "Whisper engine transcription failed ({$response->status()}): {$response->body()}. " .
                "Is the service running at {$this->baseUrl}? (see tools/whisper-engine/README.md)"
            );
        }

        $data = $response->json();

        return [
            'language' => $data['language'] ?? null,
            'segments' => array_map(fn ($s) => [
                'start' => (float) $s['start'],
                'end' => (float) $s['end'],
                'text' => trim((string) $s['text']),
            ], $data['segments'] ?? []),
            'words' => array_map(fn ($w) => [
                'word' => (string) $w['word'],
                'start' => (float) $w['start'],
                'end' => (float) $w['end'],
            ], $data['words'] ?? []),
        ];
    }
}
