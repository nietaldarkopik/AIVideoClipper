<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialPost;
use App\Models\SocialPostMetricSnapshot;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Reads back what the published clips actually did on each platform, from the
 * metrics SocialMetricsSyncer writes onto SocialPost.
 *
 * Aggregation happens in PHP rather than SQL on purpose: `metrics` is a JSON
 * column whose shape varies by platform (TikTok reports shares, YouTube doesn't;
 * only the mock providers fill watch_time), so summing it in the database would
 * mean postgres-specific JSON operators plus a COALESCE per key per platform for
 * something the number of published posts per user never makes expensive.
 */
class AnalyticsController extends Controller
{
    /**
     * Keys summed across posts. Kept explicit rather than derived from whatever
     * the provider happened to return, so one platform inventing a new key can't
     * silently reshape the response the frontend renders.
     */
    private const METRIC_KEYS = ['views', 'likes', 'comments', 'shares', 'saves'];

    public function index(Request $request)
    {
        $days = max(1, min(365, $request->integer('days', 30)));
        $since = now()->subDays($days);

        $posts = SocialPost::query()
            ->whereHas('clip.project', fn ($q) => $q->where('user_id', $request->user()->id))
            ->where('status', SocialPost::STATUS_PUBLISHED)
            ->where('published_at', '>=', $since)
            ->with(['clip:id,title,thumbnail_path,cover_path,project_id', 'socialAccount:id,account_name,platform'])
            ->orderByDesc('published_at')
            ->get();

        return response()->json([
            'range_days' => $days,
            'totals' => $this->totals($posts),
            'by_platform' => $this->byPlatform($posts),
            'top_posts' => $this->topPosts($posts),
            'timeseries' => $this->timeseries($posts, $days),
            // Drives the "last updated x ago" line, so a stale sync (a stopped
            // scheduler, an expired token) is visible instead of being mistaken
            // for genuinely flat numbers.
            'last_synced_at' => $posts->max('metrics_synced_at'),
            'awaiting_first_sync' => $posts->whereNull('metrics_synced_at')->count(),
        ]);
    }

    /**
     * @param  Collection<int, SocialPost>  $posts
     */
    private function totals(Collection $posts): array
    {
        $totals = ['posts' => $posts->count()];

        foreach (self::METRIC_KEYS as $key) {
            $totals[$key] = (int) $posts->sum(fn (SocialPost $p) => (int) ($p->metrics[$key] ?? 0));
        }

        // Engagement rate against views, the way every platform's own dashboard
        // reports it. Guarded because a post published minutes ago legitimately
        // has zero views and dividing by it would render NaN in the UI.
        $totals['engagement_rate'] = $totals['views'] > 0
            ? round((($totals['likes'] + $totals['comments'] + $totals['shares'] + $totals['saves']) / $totals['views']) * 100, 2)
            : 0.0;

        return $totals;
    }

    /**
     * @param  Collection<int, SocialPost>  $posts
     */
    private function byPlatform(Collection $posts): array
    {
        return $posts->groupBy('platform')
            ->map(function (Collection $group, string $platform) {
                $row = ['platform' => $platform, 'posts' => $group->count()];

                foreach (self::METRIC_KEYS as $key) {
                    $row[$key] = (int) $group->sum(fn (SocialPost $p) => (int) ($p->metrics[$key] ?? 0));
                }

                $row['avg_views'] = $group->count() > 0 ? (int) round($row['views'] / $group->count()) : 0;

                return $row;
            })
            ->sortByDesc('views')
            ->values()
            ->all();
    }

    /**
     * Views and likes GAINED per day, not the running totals the platforms report.
     *
     * The distinction is the whole point of the chart. Summing cumulative readings
     * per day produces a line that only ever climbs — and one that dips whenever a
     * post ages past the last sync tier and stops being read, which looks like
     * losing views. Differencing each post's consecutive readings instead gives
     * "what did the catalogue earn that day", where an unmeasured post simply
     * contributes nothing.
     *
     * @param  Collection<int, SocialPost>  $posts
     */
    private function timeseries(Collection $posts, int $days): array
    {
        if ($posts->isEmpty()) {
            return [];
        }

        $snapshots = SocialPostMetricSnapshot::query()
            ->whereIn('social_post_id', $posts->pluck('id'))
            ->where('captured_on', '>=', now()->subDays($days)->toDateString())
            ->orderBy('social_post_id')
            ->orderBy('captured_on')
            ->get();

        // Seeded with every day in the window so the chart keeps an even x-axis
        // instead of collapsing days where nothing was synced.
        $byDay = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $byDay[now()->subDays($i)->toDateString()] = ['date' => now()->subDays($i)->toDateString(), 'views' => 0, 'likes' => 0];
        }

        foreach ($snapshots->groupBy('social_post_id') as $postSnapshots) {
            $previous = null;

            foreach ($postSnapshots as $snapshot) {
                $day = $snapshot->captured_on->toDateString();

                // A post's first reading in the window counts in full: whatever it
                // had accumulated by then is the earliest thing we can attribute.
                // Later readings count only the growth since the previous one.
                // max(0): platforms do revise counts downward (spam removal, a
                // deleted comment), and a negative day would read as lost views.
                $viewsGained = $previous ? max(0, $snapshot->views - $previous->views) : $snapshot->views;
                $likesGained = $previous ? max(0, $snapshot->likes - $previous->likes) : $snapshot->likes;

                if (isset($byDay[$day])) {
                    $byDay[$day]['views'] += $viewsGained;
                    $byDay[$day]['likes'] += $likesGained;
                }

                $previous = $snapshot;
            }
        }

        return array_values($byDay);
    }

    /**
     * @param  Collection<int, SocialPost>  $posts
     */
    private function topPosts(Collection $posts): array
    {
        return $posts
            // Never synced means "unknown", not "zero" — including those would
            // pad the bottom of the leaderboard with posts that may well be the
            // best performers, just not measured yet.
            ->filter(fn (SocialPost $p) => $p->metrics !== null)
            ->sortByDesc(fn (SocialPost $p) => (int) ($p->metrics['views'] ?? 0))
            ->take(10)
            ->map(fn (SocialPost $p) => [
                'id' => $p->id,
                'clip_id' => $p->clip_id,
                'title' => $p->clip?->title,
                'thumbnail_url' => $p->clip ? Media::url($p->clip->displayThumbnailPath()) : null,
                'platform' => $p->platform,
                'account_name' => $p->socialAccount?->account_name,
                'post_url' => $p->post_url,
                'published_at' => $p->published_at,
                'metrics' => $p->metrics,
            ])
            ->values()
            ->all();
    }
}
