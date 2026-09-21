<?php

namespace App\Http\Controllers\Api\Research;

use App\Http\Controllers\Controller;
use App\Http\Resources\Research\ContentIdeaResource;
use App\Http\Resources\Research\ResearchRunResource;
use App\Http\Resources\Research\ResearchSourceResource;
use App\Models\ContentChannel;
use App\Models\ContentIdea;
use App\Models\ResearchRun;
use App\Models\ResearchSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One aggregate call for the research dashboard (spec section 25) instead of the
 * eight the page would otherwise fire on load.
 */
class ResearchDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $channelIds = $user->contentChannels()->pluck('id');

        $today = now(config('app.timezone'))->toDateString();

        $ideas = ContentIdea::query()->where('user_id', $user->id);

        return response()->json([
            'summary' => [
                'channels_total' => $channelIds->count(),
                'channels_scheduled' => ContentChannel::whereIn('id', $channelIds)->where('scheduler_enabled', true)->where('is_active', true)->count(),
                'ideas_today' => (clone $ideas)->whereDate('research_date', $today)->count(),
                'ideas_total' => (clone $ideas)->count(),
                'ideas_waiting_review' => (clone $ideas)->where('status', ContentIdea::STATUS_IDEA)->count(),
                'ideas_high_priority' => (clone $ideas)->where('status', ContentIdea::STATUS_IDEA)->where('priority_score', '>=', 75)->count(),
                'ideas_selected' => (clone $ideas)->where('status', ContentIdea::STATUS_SELECTED)->count(),
            ],

            'ideas_by_channel' => ContentChannel::whereIn('id', $channelIds)
                ->withCount([
                    'contentIdeas',
                    'contentIdeas as ideas_today_count' => fn ($query) => $query->whereDate('research_date', $today),
                ])
                ->orderByDesc('content_ideas_count')
                ->get(['id', 'name', 'platform_id', 'niche', 'scheduler_enabled', 'last_research_at', 'next_research_at'])
                ->map(fn (ContentChannel $channel) => [
                    'id' => $channel->id,
                    'name' => $channel->name,
                    'niche' => $channel->niche,
                    'scheduler_enabled' => $channel->scheduler_enabled,
                    'ideas_count' => $channel->content_ideas_count,
                    'ideas_today_count' => $channel->ideas_today_count,
                    'last_research_at' => $channel->last_research_at,
                    'next_research_at' => $channel->next_research_at,
                ]),

            'top_ideas' => ContentIdeaResource::collection(
                (clone $ideas)->where('status', ContentIdea::STATUS_IDEA)
                    ->with('channel:id,name,platform_id')
                    ->orderByDesc('priority_score')
                    ->limit(10)
                    ->get()
            ),

            'recent_runs' => ResearchRunResource::collection(
                ResearchRun::whereIn('content_channel_id', $channelIds)
                    ->with('channel:id,name,platform_id')
                    ->latest('id')
                    ->limit(10)
                    ->get()
            ),

            'scheduler' => [
                'last_success_at' => ResearchRun::whereIn('content_channel_id', $channelIds)
                    ->where('status', ResearchRun::STATUS_SUCCESS)
                    ->max('finished_at'),
                'next_research_at' => ContentChannel::whereIn('id', $channelIds)
                    ->where('scheduler_enabled', true)
                    ->whereNotNull('next_research_at')
                    ->min('next_research_at'),
                'runs_failed_24h' => ResearchRun::whereIn('content_channel_id', $channelIds)
                    ->whereIn('status', [ResearchRun::STATUS_FAILED, ResearchRun::STATUS_PARTIAL])
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
                'running' => ResearchRun::whereIn('content_channel_id', $channelIds)
                    ->where('status', ResearchRun::STATUS_RUNNING)
                    ->count(),
            ],

            // Provider health is global (research_sources is a shared registry), so it
            // is reported for every source regardless of which channels use it.
            'provider_health' => ResearchSourceResource::collection(
                ResearchSource::orderBy('name')->get()
            ),

            'trending_topics' => $this->trendingTopics($channelIds->all()),
        ]);
    }

    /**
     * Topics seen across the most channels — the cross-channel view a per-channel
     * run cannot show on its own.
     *
     * Grouped by (topic_key, day) rather than just topic_key: a plain topic_key
     * group with no date and a short 48h window made a topic from a few days ago
     * silently vanish from this widget the moment newer research came in, even
     * though the underlying research_results rows were never touched. Grouping by
     * day too — and returning that date — lets each day's cross-channel topics
     * stay visible on their own, same as content_ideas: added day over day, never
     * replaced.
     *
     * @param  int[]  $channelIds
     * @return array<int, array<string, mixed>>
     */
    private function trendingTopics(array $channelIds, int $lookbackDays = 14): array
    {
        if ($channelIds === []) {
            return [];
        }

        return DB::table('research_results')
            ->select(
                'topic_key',
                DB::raw('DATE(created_at) as research_date'),
                DB::raw('COUNT(*) as mentions'),
                DB::raw('COUNT(DISTINCT source_key) as sources'),
                DB::raw('MIN(title) as sample_title'),
            )
            ->whereIn('content_channel_id', $channelIds)
            ->whereNotNull('topic_key')
            ->where('topic_key', '!=', '')
            ->where('created_at', '>=', now()->subDays($lookbackDays))
            ->groupBy('topic_key', DB::raw('DATE(created_at)'))
            // Newest day first, so today's cross-channel topics always lead — earlier
            // days stay listed below rather than being pushed out of the result set.
            ->orderByDesc('research_date')
            // Within a day, breadth of independent sources first: a topic covered by
            // four different sources is a stronger signal than one mentioned forty
            // times by a single feed.
            ->orderByDesc('sources')
            ->orderByDesc('mentions')
            ->limit(60)
            ->get()
            ->map(fn ($row) => [
                'topic_key' => $row->topic_key,
                'title' => $row->sample_title,
                'mentions' => (int) $row->mentions,
                'sources' => (int) $row->sources,
                'research_date' => $row->research_date,
            ])
            ->all();
    }
}
