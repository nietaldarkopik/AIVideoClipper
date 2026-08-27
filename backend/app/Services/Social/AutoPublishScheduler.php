<?php

namespace App\Services\Social;

use App\Jobs\PublishClipJob;
use App\Models\Clip;
use App\Models\Project;
use App\Models\PublishingProfile;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

                $cursors[$account->id] = $slot->clone()->addSeconds(random_int($staggerMinSeconds, $staggerMaxSeconds));
            }
        }

        return $scheduledCount;
    }

    /**
     * Walks $notBefore forward, day by day, until it lands on a calendar day where
     * $account hasn't already claimed $maxPerDay slots — counting every post that
     * actually occupies a slot (scheduled, in-flight, published, or even failed —
     * a failed attempt still hit the platform around that time) but not ones that
     * never got that far (still 'ready') or were explicitly freed ('cancelled').
     */
    private function nextAvailableSlot(SocialAccount $account, Carbon $notBefore, int $maxPerDay): Carbon
    {
        $slot = $notBefore->clone();

        while ($this->claimedSlotsOnDay($account, $slot) >= $maxPerDay) {
            $slot = $slot->clone()->startOfDay()->addDay()->addHours(self::OVERFLOW_DAY_START_HOUR);
        }

        return $slot;
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
