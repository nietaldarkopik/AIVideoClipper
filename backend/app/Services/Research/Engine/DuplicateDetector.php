<?php

namespace App\Services\Research\Engine;

use App\Models\ContentChannel;
use App\Models\ContentIdea;

/**
 * Stops the engine from proposing what a channel has already been given.
 *
 * Scoped to ONE channel on purpose: the same trending topic legitimately belongs
 * to several channels with different angles (spec section 20), so cross-channel
 * matches are never treated as duplicates.
 *
 * Loaded once per run and held in memory rather than queried per candidate — a
 * run checks dozens of candidates against hundreds of prior ideas, and per-check
 * SQL made that the slowest stage of the pipeline.
 */
class DuplicateDetector
{
    /** @var array<int, array{title: string, tokens: string[]}> */
    private array $existing = [];

    private float $threshold;

    public function __construct()
    {
        $this->threshold = (float) config('research.duplicates.similarity_threshold', 0.62);
    }

    public function loadFor(ContentChannel $channel): self
    {
        $lookbackDays = max(1, (int) config('research.duplicates.lookback_days', 90));

        $this->existing = ContentIdea::query()
            ->where('content_channel_id', $channel->id)
            ->whereIn('status', ContentIdea::DEDUPE_STATUSES)
            ->where('created_at', '>=', now()->subDays($lookbackDays))
            ->orderByDesc('id')
            ->limit(500)
            ->get(['title', 'topic', 'keywords'])
            ->map(fn (ContentIdea $idea) => [
                'title' => $idea->title,
                'tokens' => TextSignature::tokens(
                    $idea->title.' '.$idea->topic.' '.implode(' ', is_array($idea->keywords) ? $idea->keywords : [])
                ),
            ])
            ->all();

        return $this;
    }

    /**
     * Highest similarity against anything the channel already has, 0..1.
     */
    public function similarityOf(string $text): float
    {
        $tokens = TextSignature::tokens($text);

        if ($tokens === []) {
            return 0.0;
        }

        $highest = 0.0;

        foreach ($this->existing as $existing) {
            $similarity = TextSignature::similarity($tokens, $existing['tokens']);
            if ($similarity > $highest) {
                $highest = $similarity;
                if ($highest >= 1.0) {
                    break;
                }
            }
        }

        return $highest;
    }

    public function isDuplicate(string $text): bool
    {
        return $this->similarityOf($text) >= $this->threshold;
    }

    /**
     * 100 = nothing like it exists; 0 = already covered. The direct inverse of the
     * similarity, so originality and duplicate rejection can never disagree.
     */
    public function originalityScore(string $text): int
    {
        return (int) round((1 - $this->similarityOf($text)) * 100);
    }

    /**
     * Registers an idea accepted during THIS run, so two topics generated in the
     * same run cannot duplicate each other — the in-run case the DB lookup alone
     * cannot catch, since nothing is persisted until the run finishes.
     */
    public function remember(string $text): void
    {
        $this->existing[] = ['title' => $text, 'tokens' => TextSignature::tokens($text)];
    }

    /**
     * @return string[] titles shown to the AI as "already covered"
     */
    public function previousTitles(int $limit): array
    {
        return array_slice(array_column($this->existing, 'title'), 0, $limit);
    }
}
