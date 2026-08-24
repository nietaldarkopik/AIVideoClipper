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

            // Source captions are segment-level only; distribute each word's slice
            // of the segment's duration by character length rather than splitting it
            // evenly. Equal division puts a 2-letter word ("di", "ke") on screen for
            // exactly as long as an 8-letter one, which — compounded over a whole
            // segment — visibly drifts the highlighted word away from what's
            // actually being said at that instant, then snaps back in sync at the
            // next segment boundary (real cue timing resets there). Weighting by
            // length is still an approximation, not real forced alignment, but it
            // tracks natural pacing far better than a flat split. +2 per word is a
            // floor so very short words (which are spoken with some minimum
            // duration regardless of letter count) don't get an unreadably thin
            // sliver of time.
            $wordList = preg_split('/\s+/', $seg['text']);
            $wordCount = max(count($wordList), 1);
            $segmentDuration = $seg['end'] - $seg['start'];
            $weights = array_map(fn (string $w) => mb_strlen($w) + 2, $wordList);
            $totalWeight = array_sum($weights) ?: $wordCount;
            $cursor = $seg['start'];
            foreach ($wordList as $i => $word) {
                $wordDuration = ($weights[$i] / $totalWeight) * $segmentDuration;
                $words[] = [
                    'word' => $word,
                    'start' => round($cursor, 2),
                    'end' => round($cursor + $wordDuration, 2),
                    'speaker' => 'A',
                ];
                $cursor += $wordDuration;
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
