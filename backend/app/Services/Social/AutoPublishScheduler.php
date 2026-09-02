<?php

namespace App\Services\Social;

use App\Jobs\PublishClipJob;
use App\Models\ChannelWatch;
use App\Models\Clip;
use App\Models\Project;
use App\Models\PublishingProfile;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\VideoBatchItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Schedules a project's completed clips for auto-publish, staggered so multiple
 * clips don't all post to the same accounts back-to-back (reads as spammy to
 * platforms and risks a ban). Shared by ProcessBatchItemJob (the batch autobot,
 * which always auto-publishes once a video's clips are rendered) and
 * RenderClipJob (regular, non-batch projects — see its settleProjectStatus,
 * which now does the same once every clip finishes rendering).
 *
 * Idempotent: safe to call repeatedly for the same project — already
 * published/scheduled (clip, account) pairs are left alone, so a retry or a
 * second render-completion event never double-schedules a post.
 */
class AutoPublishScheduler
{
    // Defaults used when the caller doesn't have a per-batch/per-channel override
    // (e.g. a regular, non-batch project — see RenderClipJob::settleProjectStatus).
    public const STAGGER_MIN_SECONDS = 1800;

    public const STAGGER_MAX_SECONDS = 3600;

    // Platforms flag rapid-fire posting from one account as spammy/bot-like — this
    // caps how many posts AutoPublishScheduler will ever queue onto a single social
    // account for a single calendar day, regardless of how many clips a video (or a
    // whole batch of videos) produces. Anything past the cap rolls forward to the
    // next day instead of being dropped — see nextAvailableSlot().
    public const MAX_POSTS_PER_DAY_PER_ACCOUNT = 5;

    // Clock hour overflow posts land on when they roll onto a fresh day, so a
    // rollover doesn't post at whatever odd hour the cap happened to be hit.
    private const OVERFLOW_DAY_START_HOUR = 9;

    // Used only to size the gap between consecutive posts on the same account
    // (see spreadGapSeconds()) — NOT a hard constraint like an explicit
    // window_start_hour/window_end_hour (those still default to "off", posts
    // can land at any hour). Without this, a small fixed stagger gap (e.g.
    // 30-120min) combined with maxPerDay posts fills only the first few hours
    // after a rollover (9:00-13:30ish for 5 posts) and leaves the rest of the
    // day — afternoon, evening, night — completely empty, then the whole
    // pattern repeats identically the next day. Spacing gaps out across a
    // nominal 9:00-21:00 "posting day" instead spreads a day's cap across
    // actual daytime hours.
    private const DEFAULT_SPREAD_END_HOUR = 21;

    /**
     * @return int number of posts newly scheduled by this call
     */
    public function scheduleForProject(
        Project $project,
        ?int $publishingProfileId = null,
        ?int $staggerMinSeconds = null,
        ?int $staggerMaxSeconds = null,
        ?int $maxPostsPerDayPerAccount = null,
    ): int {
        $clips = Clip::where('project_id', $project->id)->where('status', Clip::STATUS_COMPLETED)->get();
        $accounts = $this->resolvePublishTargets($project->user, $publishingProfileId);

        if ($clips->isEmpty() || $accounts->isEmpty()) {
            Log::info('Auto-publish: nothing to schedule', [
                'project_id' => $project->id,
                'completed_clip_count' => $clips->count(),
                'target_account_count' => $accounts->count(),
            ]);

            return 0;
        }

        $staggerMinSeconds ??= self::STAGGER_MIN_SECONDS;
        $staggerMaxSeconds ??= self::STAGGER_MAX_SECONDS;
        // random_int() throws if min > max — a batch/channel with a misconfigured
        // (or since-changed) min > max shouldn't ever crash publishing over it.
        $staggerMaxSeconds = max($staggerMinSeconds, $staggerMaxSeconds);
        // Guaranteed >= 1: a misconfigured 0 (or negative) would make
        // nextAvailableSlot()'s day-rollover loop spin forever, since a brand new
        // day always starts at a claimed count of 0.
        $maxPostsPerDayPerAccount = max(1, $maxPostsPerDayPerAccount ?? self::MAX_POSTS_PER_DAY_PER_ACCOUNT);

        $scheduledCount = 0;
        // Each account gets its own cursor (not one shared $scheduledAt for every
        // account, like before this cap existed) — accounts can already be sitting
        // at a different point in their own daily cap from an earlier
        // video/batch run, so they can't share a single timeline.
        $cursors = [];
        foreach ($accounts as $account) {
            $cursors[$account->id] = now();
        }

        foreach ($clips as $clip) {
            foreach ($accounts as $account) {
                $post = SocialPost::firstOrCreate(
                    ['clip_id' => $clip->id, 'social_account_id' => $account->id],
                    [
                        'platform' => $account->platform,
                        'title' => $clip->title,
                        'caption' => $clip->caption,
                        'hashtags' => $clip->hashtags,
                        'status' => SocialPost::STATUS_READY,
                    ]
                );

                if (in_array($post->status, [SocialPost::STATUS_PUBLISHED, SocialPost::STATUS_SCHEDULED], true)) {
                    continue;
                }

                $slot = $this->nextAvailableSlot($account, $cursors[$account->id], $maxPostsPerDayPerAccount);

                $post->update(['status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => $slot]);
                PublishClipJob::dispatch($post->id)->delay($slot);
                $scheduledCount++;

                Log::info('Auto-publish: post scheduled', [
                    'post_id' => $post->id,
                    'clip_id' => $clip->id,
                    'social_account_id' => $account->id,
                    'platform' => $account->platform,
                    'scheduled_at' => $slot->toIso8601String(),
                ]);

                // Plain random_int here, not spreadGapSeconds() — this schedules
                // whatever a single project/batch just finished rendering, which
                // is typically a handful of clips, not enough to approach
                // $maxPostsPerDayPerAccount. Sizing the gap around the day cap
                // (like rescheduleBulk() does for a full-channel consolidation)
                // would stretch a 3-clip project's posts hours apart for no
                // reason — a tight, consistent 30-60min cadence per project is
                // the intended feel here (see ProcessBatchItemPublishStaggerTest).
                $cursors[$account->id] = $slot->clone()->addSeconds(random_int($staggerMinSeconds, $staggerMaxSeconds));
            }
        }

        return $scheduledCount;
    }

    /**
     * Called when a ChannelWatch's publishing_profile_id changes (see
     * ChannelWatchController::update()) — finds every already-COMPLETED clip that
     * came from this watch (via video_batches.channel_watch_id -> video_batch_items
     * -> project_id, see the migration that added that column) and moves its
     * still-pending SocialPosts onto the new profile's account(s): cancels any
     * post for an account the new profile no longer targets, then runs the
     * normal scheduleForProject() path so the correct target account(s) get a
     * fresh post at their own next available slot. A post that's already
     * PUBLISHED or in-flight (uploading/publishing) is never touched — this only
     * ever redirects something that hasn't gone out yet.
     *
     * Doesn't cover a batch item that's still rendering when the watch is fixed
     * (no SocialPost exists for it yet to redirect) — ProcessBatchItemJob
     * resolves the watch's CURRENT publishing_profile_id live instead of the
     * batch's snapshotted settings for exactly that case.
     *
     * @return int number of posts touched (cancelled + newly (re)scheduled) across every affected project
     */
    public function resyncChannelWatchProfile(ChannelWatch $watch, ?int $newPublishingProfileId): int
    {
        $projectIds = VideoBatchItem::whereHas('videoBatch', fn ($q) => $q->where('channel_watch_id', $watch->id))
            ->whereNotNull('project_id')
            ->pluck('project_id')
            ->unique();

        if ($projectIds->isEmpty()) {
            return 0;
        }

        $touched = 0;

        Project::whereIn('id', $projectIds)->get()->each(function (Project $project) use ($newPublishingProfileId, &$touched) {
            $clipIds = Clip::where('project_id', $project->id)->where('status', Clip::STATUS_COMPLETED)->pluck('id');
            if ($clipIds->isEmpty()) {
                return;
            }

            $newAccountIds = $this->resolvePublishTargets($project->user, $newPublishingProfileId)->pluck('id');

            // Anything still pending for an account the NEW profile doesn't target
            // is cancelled outright rather than left to publish to the wrong
            // place — scheduleForProject() below only ever creates/advances posts
            // for accounts the new profile DOES target, so this account's post
            // would otherwise just sit there, unrelated to the fix, and still fire.
            $stale = SocialPost::whereIn('clip_id', $clipIds)
                ->whereNotIn('status', [SocialPost::STATUS_PUBLISHED, SocialPost::STATUS_UPLOADING, SocialPost::STATUS_PUBLISHING, SocialPost::STATUS_CANCELLED])
                ->whereNotIn('social_account_id', $newAccountIds)
                ->get();

            foreach ($stale as $post) {
                $post->update(['status' => SocialPost::STATUS_CANCELLED]);
                $touched++;
            }

            $touched += $this->scheduleForProject($project, $newPublishingProfileId);
        });

        return $touched;
    }

    /**
     * Walks $notBefore forward, day by day, until it lands on a calendar day where
     * $account hasn't already claimed $maxPerDay slots — counting every post that
     * actually occupies a slot (scheduled, in-flight, published, or even failed —
     * a failed attempt still hit the platform around that time) but not ones that
     * never got that far (still 'ready') or were explicitly freed ('cancelled').
     * Public so SocialPostController::regenerateSchedule() can recompute a single
     * post's slot the exact same way — after a channel swap (the old slot was
     * picked for a different account's queue) or to bump a post to the soonest
     * non-spammy slot without bypassing the stagger/day-cap rules entirely (that's
     * what publish-now is for).
     *
     * @param  ?int  $windowStartHour  0-23; both null (default, every caller before this feature) means no daily posting-hours restriction at all — a slot can land at any hour, exactly as before.
     * @param  ?int  $windowEndHour  1-24 (24 = midnight, i.e. the window runs to the end of the day)
     */
    public function nextAvailableSlot(
        SocialAccount $account,
        Carbon $notBefore,
        int $maxPerDay,
        ?int $windowStartHour = null,
        ?int $windowEndHour = null,
    ): Carbon {
        $slot = $notBefore->clone();
        $rolloverHour = $windowStartHour ?? self::OVERFLOW_DAY_START_HOUR;

        while (true) {
            if ($windowStartHour !== null && $windowEndHour !== null && $windowStartHour < $windowEndHour) {
                $slot = $this->clampToWindow($slot, $windowStartHour, $windowEndHour);
            }

            if ($this->claimedSlotsOnDay($account, $slot) < $maxPerDay) {
                return $slot;
            }

            $slot = $slot->clone()->startOfDay()->addDay()->addHours($rolloverHour);
        }
    }

    /**
     * Pushes $slot forward into [$startHour, $endHour) on its own day if it's
     * too early, or to $startHour the NEXT day if it's at/past $endHour — never
     * pulls a slot backward in time. Only called with an already-validated
     * $startHour < $endHour (see nextAvailableSlot()).
     */
    private function clampToWindow(Carbon $slot, int $startHour, int $endHour): Carbon
    {
        if ($slot->hour < $startHour) {
            return $slot->clone()->startOfDay()->addHours($startHour);
        }
        if ($slot->hour >= $endHour) {
            return $slot->clone()->startOfDay()->addDay()->addHours($startHour);
        }

        return $slot;
    }

    /**
     * Bulk-reschedule an arbitrary set of EXISTING posts, staggered per social
     * account with a random gap in [$staggerMinSeconds, $staggerMaxSeconds] and
     * the same day-cap/rollover rules as scheduleForProject() — the "Reschedule
     * All" action on the Scheduler page. Unlike scheduleForProject() this
     * redistributes posts that already exist instead of creating new ones, so
     * it's grouped by social_account_id up front rather than iterating
     * clips x accounts. Relative order within each account is preserved (by
     * current scheduled_at, falling back to created_at for posts that were
     * never scheduled) so a bulk reshuffle doesn't change which clip goes out
     * first for a given channel — only when.
     *
     * @param  Collection<int, SocialPost>  $posts
     * @param  ?int  $windowStartHour  see nextAvailableSlot() — null (default) means no daily posting-hours restriction
     * @return int number of posts rescheduled
     */
    public function rescheduleBulk(
        Collection $posts,
        int $staggerMinSeconds = self::STAGGER_MIN_SECONDS,
        int $staggerMaxSeconds = self::STAGGER_MAX_SECONDS,
        int $maxPostsPerDayPerAccount = self::MAX_POSTS_PER_DAY_PER_ACCOUNT,
        ?int $windowStartHour = null,
        ?int $windowEndHour = null,
    ): int {
        $staggerMaxSeconds = max($staggerMinSeconds, $staggerMaxSeconds);
        $maxPostsPerDayPerAccount = max(1, $maxPostsPerDayPerAccount);

        // Every post in $posts is about to get a brand new scheduled_at from
        // this same run — but until its own turn in the loop below, its OLD
        // scheduled_at is still sitting in the DB. claimedSlotsOnDay() would
        // then count that stale, about-to-be-overwritten date against whatever
        // day it happened to land on before this run, making an otherwise-open
        // day look artificially full and forcing posts later in iteration
        // order to roll forward for no real reason — for a channel with a
        // large existing backlog being fully reshuffled, this compounds into
        // the first several days looking "full" (from the batch's own stale
        // dates) while the newly (correctly) assigned slots get pushed out
        // into a large gap further out. Clearing scheduled_at for the WHOLE
        // batch up front means claimedSlotsOnDay only ever counts posts truly
        // outside this batch, plus this batch's own posts once THEY'VE
        // actually been placed by this loop. The in-memory $posts collection
        // (used below for per-account ordering) still has each post's original
        // scheduled_at attribute, since a raw DB update doesn't touch already-
        // loaded models — ordering is unaffected.
        SocialPost::whereIn('id', $posts->pluck('id'))->update(['scheduled_at' => null]);

        $rescheduledCount = 0;
        foreach ($posts->groupBy('social_account_id') as $accountPosts) {
            $account = $accountPosts->first()?->socialAccount;
            if (! $account) {
                continue;
            }

            $ordered = $accountPosts->sortBy(fn (SocialPost $p) => $p->scheduled_at ?? $p->created_at);
            $cursor = now();

            // Only spread gaps out to fill the day when this account actually HAS
            // enough posts in this batch to make that meaningful — capping the
            // spread divisor at the account's own post count (not the bare
            // $maxPostsPerDayPerAccount ceiling) means rescheduling a small handful
            // of posts for a channel still gets the tight, caller-specified gap
            // (the ceiling is a safety cap for busy days, not a target every batch
            // is assumed to fill) while a large backlog — the case that actually
            // produced empty afternoons — spreads properly.
            $spreadDivisor = min($maxPostsPerDayPerAccount, $ordered->count());

            foreach ($ordered as $post) {
                $slot = $this->nextAvailableSlot($account, $cursor, $maxPostsPerDayPerAccount, $windowStartHour, $windowEndHour);

                $post->update(['status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => $slot]);
                // Stale original delayed job (if any) is a guarded no-op — see
                // PublishClipJob::handle()'s scheduled_at->isFuture() check.
                PublishClipJob::dispatch($post->id)->delay($slot);
                $rescheduledCount++;

                $gap = $this->spreadGapSeconds($staggerMinSeconds, $staggerMaxSeconds, $spreadDivisor, $windowStartHour, $windowEndHour);
                $cursor = $slot->clone()->addSeconds($gap);
            }
        }

        return $rescheduledCount;
    }

    /**
     * How long to wait before the NEXT post on the same account, after just
     * placing one at a slot within today's cap — used only by rescheduleBulk()
     * (see its call site for why scheduleForProject() deliberately does NOT use
     * this: a small per-project batch should keep the tight, literal
     * [$minSeconds, $maxSeconds] gap, not a day-spread estimate). A plain
     * random_int($min, $max) for every post, regardless of how many are landing
     * on the same day, is what produces the clustering described on
     * DEFAULT_SPREAD_END_HOUR: 5 posts at a 30-120min gap only spans ~2-8
     * hours, so a day's quota lands in a tight morning block and the rest of
     * the day never gets a post. Instead, size the gap around (window width) /
     * $postsPerDayEstimate — the average spacing needed to actually fill the
     * day with THAT MANY posts (the caller caps this at the smaller of the
     * account's real post count in this batch and the day cap — see
     * rescheduleBulk()'s $spreadDivisor — so a handful of posts isn't force-
     * spread across a whole day just because the cap allows more) — and only
     * ever WIDEN $min/$max to hit that target, never narrow them, so an
     * explicitly tight caller-supplied gap still gets respected as a floor.
     */
    private function spreadGapSeconds(int $minSeconds, int $maxSeconds, int $postsPerDayEstimate, ?int $windowStartHour, ?int $windowEndHour): int
    {
        $startHour = $windowStartHour ?? self::OVERFLOW_DAY_START_HOUR;
        $endHour = $windowEndHour ?? self::DEFAULT_SPREAD_END_HOUR;

        if ($endHour <= $startHour) {
            // Inverted/degenerate window (shouldn't normally reach here — callers
            // validate start < end for an explicit window) — fall back to the
            // caller's own gap rather than divide by a non-positive span.
            return random_int($minSeconds, $maxSeconds);
        }

        // If chaining $postsPerDayEstimate posts at the caller's OWN average gap
        // would already reach (or overshoot) the window on its own, there's no
        // clustering problem to correct — widening further would only push
        // gaps past what the caller actually asked for. Only step in when the
        // caller's own gap would otherwise bunch everything into a fraction of
        // the window (the actual bug: 5 posts at a 30-120min gap span at most
        // ~2-8h of a 12h window, leaving the rest of the day untouched).
        $windowSeconds = ($endHour - $startHour) * 3600;
        $naiveSpan = $postsPerDayEstimate * (($minSeconds + $maxSeconds) / 2);
        if ($naiveSpan >= $windowSeconds) {
            return random_int($minSeconds, $maxSeconds);
        }

        $targetSeconds = (int) round($windowSeconds / max(1, $postsPerDayEstimate));
        $min = max($minSeconds, (int) round($targetSeconds * 0.85));
        $max = max($maxSeconds, (int) round($targetSeconds * 1.15));
        $max = max($min, $max);

        return random_int($min, $max);
    }

    private function claimedSlotsOnDay(SocialAccount $account, Carbon $day): int
    {
        return SocialPost::where('social_account_id', $account->id)
            ->whereNotIn('status', [SocialPost::STATUS_READY, SocialPost::STATUS_CANCELLED])
            ->whereDate('scheduled_at', $day->toDateString())
            ->count();
    }

    /**
     * @return Collection<int, SocialAccount>
     */
    private function resolvePublishTargets(User $user, ?int $publishingProfileId): Collection
    {
        if ($publishingProfileId) {
            $profile = PublishingProfile::with('socialAccounts')->find($publishingProfileId);
            if ($profile && $profile->user_id === $user->id) {
                return $profile->socialAccounts->where('status', SocialAccount::STATUS_CONNECTED)->values();
            }
        }

        // "Active social media" = accounts the user has flagged for auto-publish.
        return $user->socialAccounts()
            ->where('status', SocialAccount::STATUS_CONNECTED)
            ->where('auto_publish_enabled', true)
            ->get();
    }
}
