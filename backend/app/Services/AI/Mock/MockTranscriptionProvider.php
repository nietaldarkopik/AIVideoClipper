<?php

namespace App\Services\AI\Mock;

use App\Services\AI\Contracts\TranscriptionProvider;
use App\Services\AI\DTOs\TranscriptionResult;
use App\Services\Video\FFmpegService;
use Closure;

class MockTranscriptionProvider implements TranscriptionProvider
{
    public function __construct(private readonly FFmpegService $ffmpeg)
    {
    }

    public function transcribe(string $audioPath, ?string $language = null, ?Closure $shouldAbort = null): TranscriptionResult
    {
        $duration = $this->ffmpeg->probeDuration($audioPath) ?? 300.0;
        $language = $language ?? 'en';

        $sentences = MockContentBank::fillerSentences();
        $hooks = MockContentBank::hooks();

        // Seed randomness deterministically off duration + path so re-runs on the
        // same file produce a stable-looking (but not identical) transcript.
        mt_srand((int) ($duration * 1000) % 100000 + strlen($audioPath));

        $segments = [];
        $words = [];
        $speakers = [
            ['id' => 'A', 'label' => 'Speaker A'],
            ['id' => 'B', 'label' => 'Speaker B'],
        ];

        $time = 0.0;
        $sentenceIndex = 0;
        $fullTextParts = [];
        $speakerSwitchEvery = mt_rand(3, 6);
        $segmentCount = 0;

        while ($time < $duration - 2) {
            // Occasionally splice in a "hook-style" line so the analysis provider
            // has strong candidate moments to find later.
            if ($segmentCount > 0 && $segmentCount % 6 === 0) {
                $type = MockContentBank::MOMENT_TYPES[array_rand(MockContentBank::MOMENT_TYPES)];
                $text = $hooks[$type][array_rand($hooks[$type])];
            } else {
                $text = $sentences[$sentenceIndex % count($sentences)];
                $sentenceIndex++;
            }

            $wordsInSentence = preg_split('/\s+/', trim($text));
            $wordCount = max(count($wordsInSentence), 1);
            // ~2.5 words/sec average speaking pace
            $segmentDuration = min(max($wordCount / 2.5, 2.0), 9.0);
            $end = min($time + $segmentDuration, $duration);

            $speaker = (intdiv($segmentCount, $speakerSwitchEvery) % 2 === 0) ? 'A' : 'B';

            $segments[] = [
                'start' => round($time, 2),
                'end' => round($end, 2),
                'text' => $text,
                'speaker' => $speaker,
            ];
            $fullTextParts[] = $text;

            $perWord = ($end - $time) / $wordCount;
            foreach ($wordsInSentence as $i => $word) {
                $wStart = $time + $i * $perWord;
                $wEnd = $wStart + $perWord;
                $words[] = [
                    'word' => $word,
                    'start' => round($wStart, 2),
                    'end' => round($wEnd, 2),
                    'speaker' => $speaker,
                ];
            }

            $time = $end + 0.15; // small natural gap
            $segmentCount++;
        }

        return new TranscriptionResult(
            language: $language,
            fullText: implode(' ', $fullTextParts),
            segments: $segments,
            words: $words,
            speakers: $speakers,
            provider: 'mock',
        );
    }
}
