<?php

namespace App\Services\AI\Mock;

use App\Models\Clip;
use App\Services\AI\Contracts\SocialMetadataProvider;

class MockSocialMetadataProvider implements SocialMetadataProvider
{
    public function generateMetadata(Clip $clip, array $platforms, ?string $referenceContent = null): array
    {
        $title = $clip->title ?: 'This Changes Everything';
        $hook = $clip->clipCandidate?->hook_text ?? $clip->caption ?? $title;
        $hashtags = $clip->hashtags ?: ['#fyp', '#viral', '#contentcreator'];

        $result = [];

        foreach ($platforms as $platform) {
            $result[$platform] = match ($platform) {
                'tiktok' => [
                    'caption' => "{$hook} 👀",
                    'hashtags' => array_slice($hashtags, 0, 5),
                ],
                'instagram' => [
                    'caption' => "{$hook}\n\nFull story in this clip.",
                    'hashtags' => array_slice($hashtags, 0, 8),
                ],
                'youtube' => [
                    'title' => mb_strlen($title) > 90 ? mb_substr($title, 0, 87) . '...' : $title,
                    'description' => "{$hook}\n\nWatch until the end.\n\n" . implode(' ', $hashtags),
                    'hashtags' => array_slice($hashtags, 0, 3),
                ],
                'facebook' => [
                    'caption' => $hook,
                    'hashtags' => array_slice($hashtags, 0, 5),
                ],
                'x' => [
                    'caption' => mb_substr($hook, 0, 240),
                    'hashtags' => array_slice($hashtags, 0, 2),
                ],
                'linkedin' => [
                    'caption' => "{$hook}\n\nA short lesson worth two minutes of your time.",
                    'hashtags' => array_slice($hashtags, 0, 3),
                ],
                default => [
                    'caption' => $hook,
                    'hashtags' => array_slice($hashtags, 0, 5),
                ],
            };

            $result[$platform]['cta'] = 'Follow for more like this.';
            $result[$platform]['first_comment'] = 'What did you think? Let me know below 👇';
        }

        return $result;
    }
}
