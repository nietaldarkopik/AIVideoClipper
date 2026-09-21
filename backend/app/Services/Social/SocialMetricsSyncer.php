<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostMetricSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls per-post performance numbers (views/likes/comments/...) back from each
 * platform into SocialPost::$metrics. Single source of truth shared by the
 * scheduled `social:sync-metrics` command and the manual "refresh" button
 * (SocialPostController::syncMetrics), so an on-demand refresh behaves
 * identically to the background sync.
 *
 * Every provider already implements fetchMetrics(); until this existed nothing
 * ever called it, so the metrics/metrics_synced_at columns stayed permanently
 * null and the app could produce content but never learn which content worked.
 */
class SocialMetricsSyncer
{
    /**
     * How stale a post's metrics may get before it's re-synced, keyed by how long
     * ago it was published. Fresh posts move fast and are what the user is
     * actually watching; a two-month-old post has essentially stopped changing,
     * so it drops off the list entirely rather than costing an API call forever.
     * Every platform here has a rate-limited API (YouTube's 10k units/day is
     * shared with channel polling — see bootstrap/app.php), so the tiers exist to
     * keep a growing back catalogue from crowding out the posts that matter.
     *
     * @var array<int, array{max_age_hours: int, every_minutes: int}>
     */
    private const TIERS = [
        ['max_age_hours' => 48, 'every_minutes' => 60],
        ['max_age_hours' => 24 * 14, 'every_minutes' => 60 * 6],
        ['max_age_hours' => 24 * 60, 'every_minutes' => 60 * 24],
    ];

    public function __construct(private readonly SocialProviderManager $providers) {}

    /**
     * Posts whose metrics are stale enough to be worth another API call, oldest
     * sync first so a run that hits a per-run limit still makes progress on the
     * most neglected rows instead of re-syncing the same head of the list.
     */
    public function dueQuery(): Builder
    {
        return SocialPost::query()
            ->where('status', SocialPost::STATUS_PUBLISHED)
            // Nothing to look up without the platform's own id for the post.
            ->whereNotNull('external_post_id')
            ->whereNotNull('published_at')
            // An expired/revoked account can't be called; refreshing its token is
            // RefreshSocialTokens' job, and once that succeeds these rows become
            // due again on the next tick.
            ->whereHas('socialAccount', fn ($q) => $q->where('status', SocialAccount::STATUS_CONNECTED))
            ->where(function (Builder $q) {
                foreach (self::TIERS as $tier) {
                    $q->orWhere(function (Builder $tierQuery) use ($tier) {
                        $tierQuery
                            ->where('published_at', '>=', now()->subHours($tier['max_age_hours']))
                            ->where(fn (Builder $staleness) => $staleness
                                ->whereNull('metrics_synced_at')
                                ->orWhere('metrics_synced_at', '<', now()->subMinutes($tier['every_minutes'])));
                    });
                }
            })
            ->orderByRaw('metrics_synced_at IS NOT NULL')
            ->orderBy('metrics_synced_at');
    }

    /**
     * Syncs one post. Returns false (and leaves the previous numbers in place)
     * when the platform call fails — a transient API error should never blank out
     * metrics the UI is already showing. metrics_synced_at is deliberately left
     * untouched on failure too, so the row stays due and gets retried on the next
     * tick instead of waiting out a full tier interval.
     */
    public function syncOne(SocialPost $post): bool
    {
        try {
            $metrics = $this->providers->resolve($post->platform)->fetchMetrics($post);
        } catch (Throwable $e) {
            Log::warning('Metrics sync failed', [
                'social_post_id' => $post->id,
                'platform' => $post->platform,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $post->update([
            'metrics' => $metrics,
            'metrics_synced_at' => now(),
        ]);

        $this->recordSnapshot($post, $metrics);

        return true;
    }

    /**
     * Files this reading under today's date, overwriting any earlier reading from
     * the same day. `metrics` on the post itself only ever holds the latest
     * numbers, which can say how a post is doing but never whether it's still
     * growing — this is the history the trend chart is built from.
     *
     * updateOrCreate rather than an insert: fresh posts sync hourly, and keeping
     * every one of those would store ~24 rows a day per post to express what the
     * day's final figure already says.
     */
    private function recordSnapshot(SocialPost $post, array $metrics): void
    {
        SocialPostMetricSnapshot::updateOrCreate(
            // startOfDay(), not toDateString(): the `date` cast writes through the
            // connection's full datetime format, so a bare 'Y-m-d' string in the
            // lookup never matches the 'Y-m-d 00:00:00' that was stored — every
            // sync would then miss the existing row and hit the unique index.
            ['social_post_id' => $post->id, 'captured_on' => now()->startOfDay()],
            [
                'views' => (int) ($metrics['views'] ?? 0),
                'likes' => (int) ($metrics['likes'] ?? 0),
                'comments' => (int) ($metrics['comments'] ?? 0),
                'shares' => (int) ($metrics['shares'] ?? 0),
                'saves' => (int) ($metrics['saves'] ?? 0),
            ],
        );
    }
}
