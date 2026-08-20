<?php

namespace App\Services\Video;

use App\Services\AI\DTOs\TranscriptionResult;

/**
 * Turns a source-provided .srt (e.g. real YouTube captions downloaded by yt-dlp)
 * into the same TranscriptionResult shape a TranscriptionProvider would produce,
 * so it can be saved as a Transcript and used by everything downstream (moment
 * analysis, per-clip caption burn-in) exactly like a transcribed video would be.
 *
 * Shared by AnalyzeVideoJob (the "use captions from source" fast path) and the
 * clips:import-punchlines command (manual/"cowork" analysis of an already-
 * downloaded .srt that never went through AnalyzeVideoJob, or whose automatic
 * moment-detection came back empty).
 */
class SrtTranscriptionBuilder
{
    public function build(string $srtContent, string $language): TranscriptionResult
    {
        $segments = SrtParser::parse($srtContent);

        $words = [];
        $fullTextParts = [];
        foreach ($segments as $seg) {
            $fullTextParts[] = $seg['text'];

            // Source captions are segment-level only; interpolate even word spacing
            // within each segment so word-by-word caption highlighting still works.
            $wordList = preg_split('/\s+/', $seg['text']);
            $wordCount = max(count($wordList), 1);
            $perWord = ($seg['end'] - $seg['start']) / $wordCount;
            foreach ($wordList as $i => $word) {
                $wStart = $seg['start'] + $i * $perWord;
                $words[] = [
                    'word' => $word,
                    'start' => round($wStart, 2),
                    'end' => round($wStart + $perWord, 2),
                    'speaker' => 'A',
                ];
            }
        }

        return new TranscriptionResult(
            language: $language,
            fullText: implode(' ', $fullTextParts),
            segments: $segments,
            words: $words,
            speakers: [['id' => 'A', 'label' => 'Speaker A']],
            provider: 'source_captions',
        );
    }
}
