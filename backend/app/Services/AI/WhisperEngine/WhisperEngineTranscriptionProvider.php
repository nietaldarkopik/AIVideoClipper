<?php

namespace App\Services\AI\WhisperEngine;

use App\Services\AI\Contracts\TranscriptionProvider;
use App\Services\AI\DTOs\TranscriptionResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real transcription via our own local faster-whisper service (tools/whisper-engine),
 * a small FastAPI process that keeps the model loaded and returns word-level
 * timestamps directly — no OpenAI API key, no per-minute cost, no 25MB upload cap.
 *
 * faster-whisper doesn't do speaker diarization, so every segment/word is tagged
 * speaker "A", same convention as OpenAITranscriptionProvider.
 */
class WhisperEngineTranscriptionProvider implements TranscriptionProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 1200,
    ) {
    }

    public function transcribe(string $audioPath, ?string $language = null): TranscriptionResult
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

        $segments = array_map(fn ($s) => [
            'start' => (float) $s['start'],
            'end' => (float) $s['end'],
            'text' => trim((string) $s['text']),
            'speaker' => 'A',
        ], $data['segments'] ?? []);

        $words = array_map(fn ($w) => [
            'word' => (string) $w['word'],
            'start' => (float) $w['start'],
            'end' => (float) $w['end'],
            'speaker' => 'A',
        ], $data['words'] ?? []);

        return new TranscriptionResult(
            language: $language ?? (string) ($data['language'] ?? 'unknown'),
            fullText: implode(' ', array_column($segments, 'text')),
            segments: $segments,
            words: $words,
            speakers: [['id' => 'A', 'label' => 'Speaker A']],
            provider: 'whisper_engine',
        );
    }
}
