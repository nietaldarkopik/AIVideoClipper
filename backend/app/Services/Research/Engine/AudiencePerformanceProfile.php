<?php

namespace App\Services\Research\Engine;

use App\Models\SocialPost;
use App\Models\User;

/**
 * Builds a "what has actually worked for this user" vocabulary from the metrics
 * SocialMetricsSyncer collects: the words appearing in their best-performing
 * published clips, weighted by how well those clips did.
 *
 * This is a SIMILARITY signal, not an attribution one, and the distinction is
 * deliberate. Nothing currently links a ContentIdea to the clip it produced (see
 * the projects.origin_type migration, which starts closing that gap), so this
 * cannot say "ideas from this topic performed well". It says the weaker but still
 * true thing: "your audience responds to clips about X". Once origin data has
 * accumulated, this class is where a causal score would replace it.
 *
 * Returns null rather than an empty profile when there isn't enough history —
 * TopicScorer then drops the component from the weighted average entirely, so a
 * new user's rankings are unchanged instead of being uniformly dragged toward
 * zero by a signal nobody has data for.
 */
class AudiencePerformanceProfile
{
    /**
     * Below this many measured posts, one lucky clip would define the whole
     * profile and every topic resembling it would outrank genuinely better ones.
     */
    private const MINIMUM_POSTS = 5;

    /**
     * Only the top slice feeds the profile. Including everything would describe
     * the user's average output rather than their successes — and the average is
     * exactly what they're trying to beat.
     */
    private const TOP_FRACTION = 0.4;

    /**
     * @return array<string, float>|null token => 0..1 strength, or null when there
     *                                    isn't enough measured history to judge
     */
    public function build(User $user): ?array
    {
        $posts = SocialPost::query()
            ->whereHas('clip.project', fn ($q) => $q->where('user_id', $user->id))
            ->where('status', SocialPost::STATUS_PUBLISHED)
            ->whereNotNull('metrics')
            ->with('clip:id,title,hashtags')
            ->get()
            ->filter(fn (SocialPost $post) => $post->clip !== null && (int) ($post->metrics['views'] ?? 0) > 0);

        if ($posts->count() < self::MINIMUM_POSTS) {
            return null;
        }

        $ranked = $posts->sortByDesc(fn (SocialPost $post) => (int) ($post->metrics['views'] ?? 0))->values();
        $keep = max(self::MINIMUM_POSTS, (int) ceil($ranked->count() * self::TOP_FRACTION));
        $top = $ranked->take($keep);

        $bestViews = (int) ($top->first()->metrics['views'] ?? 0);

        if ($bestViews <= 0) {
            return null;
        }

        $profile = [];

        foreach ($top as $post) {
            // Relative to the user's own best post, not an absolute number: a
            // channel whose ceiling is 2,000 views deserves the same 0-1 spread as
            // one whose ceiling is 2,000,000.
            $strength = min(1.0, (int) ($post->metrics['views'] ?? 0) / $bestViews);

            $text = trim(($post->clip->title ?? '').' '.implode(' ', $post->clip->hashtags ?? []));

            foreach (TextSignature::tokens($text) as $token) {
                // Max, not sum: a word repeated across many clips shouldn't
                // out-weigh a word from the single best-performing one. Breadth
                // isn't the signal here — peak performance is.
                $profile[$token] = max($profile[$token] ?? 0.0, $strength);
            }
        }

        return $profile === [] ? null : $profile;
    }
}
