<?php

namespace App\Services\AI\Mock;

use App\Services\AI\Contracts\StoryboardProvider;
use App\Services\AI\DTOs\StoryboardResult;

/**
 * Zero-key placeholder: takes the first ~140 words of the material (short-form length),
 * groups sentences into evenly sized scenes and uses the sentence itself as the visual
 * prompt, so the whole pipeline can be exercised end to end without any AI credentials.
 */
class MockStoryboardProvider implements StoryboardProvider
{
    private const MAX_WORDS = 140;

    public function generateStoryboard(string $topic, string $material, int $maxScenes): StoryboardResult
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($material), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sentences = $sentences ?: [trim($material) ?: $topic];

        $kept = [];
        $words = 0;
        foreach ($sentences as $sentence) {
            $words += str_word_count($sentence, 0, 'àáâãäåæçèéêëìíîïñòóôõöùúûüýÿ');
            $kept[] = $sentence;
            if ($words >= self::MAX_WORDS) {
                break;
            }
        }

        $perScene = max(1, (int) ceil(count($kept) / max(1, $maxScenes)));
        $scenes = [];

        foreach (array_chunk($kept, $perScene) as $chunk) {
            $narration = implode(' ', $chunk);
            $scenes[] = [
                'narration' => $narration,
                'visual_prompt' => 'Cartoon illustration of: ' . mb_substr($narration, 0, 200),
                'motion_prompt' => 'Slow zoom in',
            ];
        }

        return new StoryboardResult('A friendly round orange mascot with big eyes', $scenes);
    }
}
