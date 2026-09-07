<?php

namespace App\Services\Research\Engine;

use App\Models\ContentChannel;
use App\Services\Research\DTOs\ResearchItem;
use Carbon\CarbonImmutable;

/**
 * Turns a clustered topic into the six component scores plus the weighted
 * priority score. Everything is normalized to 0-100 and every weight is
 * configurable (config/research.php, overridable per channel).
 *
 * Nothing here invents data: a topic whose sources carry no engagement metrics
 * scores 0 on engagement rather than receiving a made-up baseline.
 */
class TopicScorer
{
    /**
     * @param  ResearchTopic[]  $topics
     * @return ResearchTopic[] scored, ranked by priority descending
     */
    public function score(array $topics, ContentChannel $channel): array
    {
        $keywordTokens = $this->channelTokens($channel);
        $saturation = max(1, (int) config('research.scoring.cross_source_saturation', 4));
        $weights = $channel->effectiveScoringWeights();

        foreach ($topics as $topic) {
            $topic->crossSourceScore = (int) round(min(1.0, $topic->distinctSourceCount() / $saturation) * 100);
            $topic->relevanceScore = $this->relevance($topic, $keywordTokens);
            $topic->freshnessScore = $this->freshness($topic);
            $topic->engagementScore = $this->engagement($topic);
            $topic->trendScore = $this->trend($topic);
            // Originality is only knowable against the channel's existing ideas, which
            // is DuplicateDetector's job — it overwrites this before persistence. The
            // neutral 70 here keeps priority meaningful if a caller scores without
            // running dedupe (e.g. the preview endpoint).
            $topic->originalityScore = 70;
            $topic->priorityScore = $this->priority($topic, $weights);
        }

        usort($topics, fn (ResearchTopic $a, ResearchTopic $b) => $b->priorityScore <=> $a->priorityScore);

        return $topics;
    }

    /**
     * Recomputes priority after originality is known.
     */
    public function applyOriginality(ResearchTopic $topic, int $originality, ContentChannel $channel): void
    {
        $topic->originalityScore = max(0, min(100, $originality));
        $topic->priorityScore = $this->priority($topic, $channel->effectiveScoringWeights());
    }

    /**
     * @param  array<string, float>  $weights
     */
    private function priority(ResearchTopic $topic, array $weights): int
    {
        $components = [
            'trend' => $topic->trendScore,
            'relevance' => $topic->relevanceScore,
            'originality' => $topic->originalityScore,
            'freshness' => $topic->freshnessScore,
            'engagement' => $topic->engagementScore,
            'cross_source' => $topic->crossSourceScore,
        ];

        $total = 0.0;
        $weightSum = 0.0;

        foreach ($components as $key => $value) {
            $weight = (float) ($weights[$key] ?? 0);
            $total += $value * $weight;
            $weightSum += $weight;
        }

        // Normalizing by the weight sum means an admin can raise one weight without
        // implicitly rescaling every score, and a set of weights that does not add up
        // to 1 still yields a 0-100 result.
        return $weightSum <= 0 ? 0 : (int) round(min(100, max(0, $total / $weightSum)));
    }

    /**
     * How well the topic matches this channel's niche vocabulary.
     */
    private function relevance(ResearchTopic $topic, array $keywordTokens): int
    {
        if ($keywordTokens === []) {
            // A channel with no keywords/niche configured yet: everything is equally
            // (ir)relevant. 50 rather than 0, so scheduling still produces usable ideas
            // while the user is still filling the profile in.
            return 50;
        }

        $matched = count(array_intersect($topic->tokens, $keywordTokens));

        if ($matched === 0) {
            return 0;
        }

        // Saturating at 4 matched keyword tokens: beyond that a topic is squarely in
        // the niche and extra matches say nothing more.
        return (int) round(min(1.0, $matched / 4) * 100);
    }

    private function freshness(ResearchTopic $topic): int
    {
        $window = max(1, (int) config('research.scoring.freshness_window_hours', 72));
        $now = CarbonImmutable::now();
        $best = 0;

        foreach ($topic->items as $item) {
            if ($item->publishedAt === null) {
                continue;
            }

            $ageHours = max(0, $now->diffInMinutes($item->publishedAt, absolute: true) / 60);
            $best = max($best, (int) round(max(0, 1 - ($ageHours / $window)) * 100));
        }

        // No dated item at all -> 0, not a guess. Sources like Steam's featured list
        // legitimately carry no timestamp, and the missing signal shouldn't be
        // rewarded or punished by inventing one; the freshness weight is only 0.10.
        return $best;
    }

    private function engagement(ResearchTopic $topic): int
    {
        $best = 0;

        foreach ($topic->items as $item) {
            $best = max($best, $this->engagementOf($item));
        }

        return min(100, $best);
    }

    private function engagementOf(ResearchItem $item): int
    {
        $score = (int) ($item->engagement['score'] ?? 0);
        $comments = (int) ($item->engagement['comments'] ?? 0);
        $views = (int) ($item->engagement['views'] ?? 0);

        if ($score === 0 && $comments === 0 && $views === 0) {
            return 0;
        }

        // Log scaling: raw counts differ by orders of magnitude between a 300-upvote
        // Reddit thread and a 2M-view video, and a linear scale would collapse every
        // non-video source to ~0.
        $magnitude = $score + ($comments * 2) + ($views > 0 ? $views / 1000 : 0);

        return (int) round(min(100, (log10(max(1, $magnitude)) / 5) * 100));
    }

    /**
     * "Is this actually taking off right now" — breadth of independent coverage,
     * recency, and the weight the channel assigned to the sources that saw it.
     */
    private function trend(ResearchTopic $topic): int
    {
        $volume = min(1.0, count($topic->items) / 6);
        $breadth = min(1.0, $topic->distinctSourceCount() / max(1, (int) config('research.scoring.cross_source_saturation', 4)));
        $sourceWeight = $topic->sourceWeights === []
            ? 1.0
            : min(1.5, array_sum($topic->sourceWeights) / count($topic->sourceWeights));

        $raw = (($volume * 0.35) + ($breadth * 0.35) + (($topic->freshnessScore / 100) * 0.30)) * $sourceWeight;

        return (int) round(min(100, max(0, $raw * 100)));
    }

    /**
     * @return string[]
     */
    private function channelTokens(ContentChannel $channel): array
    {
        $text = implode(' ', array_merge(
            is_array($channel->keywords) ? $channel->keywords : [],
            is_array($channel->sub_niches) ? $channel->sub_niches : [],
            array_filter([$channel->niche]),
        ));

        return TextSignature::tokens($text);
    }
}
