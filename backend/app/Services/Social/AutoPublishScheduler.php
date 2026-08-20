<?php

namespace App\Services\Social;

use App\Jobs\PublishClipJob;
use App\Models\Clip;
use App\Models\Project;
use App\Models\PublishingProfile;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
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
    public const STAGGER_MIN_SECONDS = 1800;

    public const STAGGER_MAX_SECONDS = 3600;

    /**
     * @return int number of posts newly scheduled by this call
     */
    public function scheduleForProject(Project $project, ?int $publishingProfileId = null): int
    {
        $clips = Clip::where('project_id', $project->id)->where('status', Clip::STATUS_COMPLETED)->get();
        $accounts = $this->resolvePublishTargets($project->user, $publishingProfileId);

        if ($clips->isEmpty() || $accounts->isEmpty()) {
            return 0;
        }

        $scheduledCount = 0;
        $scheduledAt = now();

        foreach ($clips as $clipIndex => $clip) {
            if ($clipIndex > 0) {
                $scheduledAt = $scheduledAt->clone()->addSeconds(random_int(self::STAGGER_MIN_SECONDS, self::STAGGER_MAX_SECONDS));
            }

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

                $post->update(['status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => $scheduledAt]);
                PublishClipJob::dispatch($post->id)->delay($scheduledAt);
                $scheduledCount++;
            }
        }

        return $scheduledCount;
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
