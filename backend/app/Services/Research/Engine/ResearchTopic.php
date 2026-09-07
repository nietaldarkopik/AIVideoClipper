<?php

namespace App\Services\Research\Engine;

use App\Services\Research\DTOs\ResearchItem;

/**
 * A cluster of research items that are all about the same thing, plus the scores
 * derived from that cluster. Mutable (unlike ResearchItem) because the pipeline
 * fills the scores in stages: correlate -> score -> rank.
 */
class ResearchTopic
{
    /** @var ResearchItem[] */
    public array $items = [];

    /** @var array<string, float> source key => weight of the channel source that produced it */
    public array $sourceWeights = [];

    public int $trendScore = 0;

    public int $relevanceScore = 0;

    public int $freshnessScore = 0;

    public int $engagementScore = 0;

    public int $crossSourceScore = 0;

    public int $originalityScore = 0;

    public int $priorityScore = 0;

    /**
     * @param  string[]  $tokens
     */
    public function __construct(
        public string $label,
        public array $tokens,
    ) {}

    public function add(ResearchItem $item, float $sourceWeight = 1.0): void
    {
        $this->items[] = $item;
        // Max, not sum: a source that returns ten articles about one topic should not
        // outweigh four independent sources each returning one. Breadth is scored
        // separately, by distinctSourceCount().
        $this->sourceWeights[$item->sourceKey] = max($this->sourceWeights[$item->sourceKey] ?? 0.0, $sourceWeight);
    }

    public function distinctSourceCount(): int
    {
        return count($this->sourceWeights);
    }

    /**
     * @return string[]
     */
    public function sourceKeys(): array
    {
        return array_keys($this->sourceWeights);
    }

    /**
     * The item that best represents the cluster: the one with the strongest
     * engagement, falling back to the first seen. Its title becomes the topic label
     * shown to the AI and stored on the idea.
     */
    public function representative(): ?ResearchItem
    {
        if ($this->items === []) {
            return null;
        }

        $best = $this->items[0];
        $bestScore = -1;

        foreach ($this->items as $item) {
            $score = (int) ($item->engagement['score'] ?? $item->engagement['views'] ?? 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $item;
            }
        }

        return $best;
    }

    /**
     * Evidence handed to the AI and persisted with the idea, newest first so the
     * prompt's most recent context is not truncated away.
     *
     * @return ResearchItem[]
     */
    public function evidence(int $limit = 8): array
    {
        $items = $this->items;

        usort($items, function (ResearchItem $a, ResearchItem $b) {
            $aTime = $a->publishedAt?->getTimestamp() ?? 0;
            $bTime = $b->publishedAt?->getTimestamp() ?? 0;

            return $bTime <=> $aTime;
        });

        return array_slice($items, 0, $limit);
    }
}
