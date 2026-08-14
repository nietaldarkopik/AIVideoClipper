<?php

namespace App\Services\AI\DTOs;

class ClipCandidateData
{
    /**
     * @param  string[]  $reasons
     * @param  string[]  $hashtags
     */
    public function __construct(
        public readonly float $startTime,
        public readonly float $endTime,
        public readonly int $overallScore,
        public readonly int $engagementScore,
        public readonly int $hookScore,
        public readonly int $storyScore,
        public readonly int $emotionalScore,
        public readonly int $informationScore,
        public readonly int $viralPotential,
        public readonly string $hookText,
        public readonly string $momentType,
        public readonly array $reasons,
        public readonly string $explanation,
        public readonly string $suggestedTitle,
        public readonly string $suggestedCaption,
        public readonly array $hashtags,
    ) {
    }

    public function toModelAttributes(int $projectId, int $videoId): array
    {
        return [
            'project_id' => $projectId,
            'video_id' => $videoId,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'duration' => round($this->endTime - $this->startTime, 2),
            'overall_score' => $this->overallScore,
            'engagement_score' => $this->engagementScore,
            'hook_score' => $this->hookScore,
            'story_score' => $this->storyScore,
            'emotional_score' => $this->emotionalScore,
            'information_score' => $this->informationScore,
            'viral_potential' => $this->viralPotential,
            'hook_text' => $this->hookText,
            'moment_type' => $this->momentType,
            'reasons' => $this->reasons,
            'explanation' => $this->explanation,
            'suggested_title' => $this->suggestedTitle,
            'suggested_caption' => $this->suggestedCaption,
            'suggested_hashtags' => $this->hashtags,
            'status' => 'pending',
        ];
    }
}
