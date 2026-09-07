<?php

namespace App\Services\AI\Mock;

use App\Models\Transcript;
use App\Services\AI\Contracts\ContentAnalysisProvider;
use App\Services\AI\DTOs\ClipCandidateData;
use App\Services\AI\DTOs\SceneMarker;
use Closure;

class MockContentAnalysisProvider implements ContentAnalysisProvider
{
    public function detectScenes(string $videoPath, float $durationSeconds): array
    {
        mt_srand((int) ($durationSeconds * 1000) % 100000);

        $scenes = [];
        $time = 0.0;
        while ($time < $durationSeconds) {
            $time += mt_rand(6, 14);
            if ($time < $durationSeconds) {
                $scenes[] = new SceneMarker(round($time, 2));
            }
        }

        return $scenes;
    }

    /**
     * @param  SceneMarker[]  $scenes
     * @return ClipCandidateData[]
     */
    public function analyzeMoments(Transcript $transcript, array $scenes, float $durationSeconds, ?Closure $shouldAbort = null): array
    {
        $hooksByType = MockContentBank::hooks();
        $flatHooks = [];
        foreach ($hooksByType as $type => $lines) {
            foreach ($lines as $line) {
                $flatHooks[$line] = $type;
            }
        }

        $segments = $transcript->segments ?? [];
        $hookSegments = array_values(array_filter(
            $segments,
            fn ($seg) => array_key_exists($seg['text'], $flatHooks)
        ));

        // Fallback: no planted hooks found (e.g. very short clip) -> sample evenly.
        if (empty($hookSegments) && ! empty($segments)) {
            $step = max(1, (int) floor(count($segments) / 5));
            for ($i = 0; $i < count($segments); $i += $step) {
                $hookSegments[] = $segments[$i];
            }
        }

        mt_srand((int) ($durationSeconds * 1000) % 100000 + count($segments));

        $candidates = [];
        foreach ($hookSegments as $seg) {
            $type = $flatHooks[$seg['text']] ?? MockContentBank::MOMENT_TYPES[array_rand(MockContentBank::MOMENT_TYPES)];

            $leadIn = mt_rand(2, 6);
            $tail = mt_rand(15, 55);
            $start = max(0, $seg['start'] - $leadIn);
            $end = min($durationSeconds, $seg['end'] + $tail);

            if ($end - $start < 12) {
                continue;
            }

            $overall = mt_rand(78, 97);
            $jitter = fn () => max(60, min(100, $overall + mt_rand(-8, 4)));

            $titles = MockContentBank::titlesFor($type);
            $hashtags = MockContentBank::hashtagsFor($type);

            $candidates[] = new ClipCandidateData(
                startTime: round($start, 2),
                endTime: round($end, 2),
                overallScore: $overall,
                engagementScore: $jitter(),
                hookScore: $jitter(),
                storyScore: $jitter(),
                emotionalScore: $jitter(),
                informationScore: $jitter(),
                viralPotential: $jitter(),
                hookText: $seg['text'],
                momentType: $type,
                reasons: MockContentBank::reasonsFor($type),
                explanation: sprintf(
                    '%s — %d/100. %s',
                    ucfirst(str_replace('_', ' ', $type)),
                    $overall,
                    implode(' + ', array_map('lcfirst', MockContentBank::reasonsFor($type)))
                ),
                suggestedTitle: $titles[array_rand($titles)],
                suggestedCaption: $seg['text'],
                hashtags: $hashtags,
                // Thumbnail-length variants: the bank's titles trimmed to the
                // same ceiling a real provider is asked to respect, so the
                // cover flow behaves identically without an AI key.
                coverTitles: ClipCandidateData::normalizeCoverStrings($titles, 42),
                coverSubtitles: ClipCandidateData::normalizeCoverStrings(
                    [strtoupper(str_replace('_', ' ', $type)), 'VIRAL', 'WAJIB TONTON'],
                    18
                ),
            );
        }

        // Highest score first.
        usort($candidates, fn ($a, $b) => $b->overallScore <=> $a->overallScore);

        return $candidates;
    }
}
