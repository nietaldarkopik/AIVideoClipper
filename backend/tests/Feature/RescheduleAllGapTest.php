<?php

namespace Tests\Feature;

use App\Models\Clip;
use App\Models\Project;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\AutoPublishScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Reschedule All" spreads a channel's posts across the posting day, but that
 * spreading is a preference INSIDE the caller's requested gap range, never a
 * licence to exceed it. Regression cover for the bug where a channel with only
 * a couple of posts in the batch had its gap stretched to window/2 (~6 hours)
 * despite the caller asking for at most 120 minutes, which showed up as whole
 * mornings with no posts on that channel.
 */
class RescheduleAllGapTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: SocialAccount}
     */
    private function userWithAccount(): array
    {
        $user = User::factory()->create();
        $account = $user->socialAccounts()->create([
            'platform' => 'tiktok', 'account_name' => 'acc', 'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        return [$user, $account];
    }

    private function scheduledPosts(User $user, SocialAccount $account, int $count): void
    {
        $project = $user->projects()->create([
            'title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now(),
        ]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 20]);

        for ($i = 0; $i < $count; $i++) {
            $clip = Clip::create([
                'project_id' => $project->id, 'video_id' => $video->id,
                'start_time' => 0, 'end_time' => 20, 'duration' => 20,
                'aspect_ratio' => '9:16', 'subtitle_language' => 'en',
                'title' => "Clip {$i}", 'status' => Clip::STATUS_COMPLETED, 'output_path' => 'clips/x.mp4',
            ]);
            SocialPost::create([
                'clip_id' => $clip->id, 'social_account_id' => $account->id, 'platform' => 'tiktok',
                'status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => now()->addDays($i + 1),
            ]);
        }
    }

    /**
     * @return array<int, int> consecutive gaps in minutes, same-day pairs only
     */
    private function sameDayGapsInMinutes(SocialAccount $account): array
    {
        $times = SocialPost::where('social_account_id', $account->id)
            ->orderBy('scheduled_at')
            ->pluck('scheduled_at');

        $gaps = [];
        for ($i = 1; $i < $times->count(); $i++) {
            // Day rollovers are the day cap doing its job, not a stagger gap.
            if ($times[$i]->isSameDay($times[$i - 1])) {
                $gaps[] = $times[$i - 1]->diffInMinutes($times[$i]);
            }
        }

        return $gaps;
    }

    public function test_a_small_batch_never_exceeds_the_requested_max_gap(): void
    {
        Queue::fake();
        [$user, $account] = $this->userWithAccount();
        // Two posts is the case that used to blow out to ~6 hours apart.
        $this->scheduledPosts($user, $account, 2);

        app(AutoPublishScheduler::class)->rescheduleBulk(
            SocialPost::with('socialAccount')->get(),
            10 * 60,   // min 10 minutes
            120 * 60,  // max 120 minutes
        );

        foreach ($this->sameDayGapsInMinutes($account) as $gap) {
            $this->assertLessThanOrEqual(120, $gap, 'Gap exceeded the requested 120-minute maximum.');
            $this->assertGreaterThanOrEqual(10, $gap, 'Gap fell below the requested 10-minute minimum.');
        }
    }

    public function test_a_full_days_worth_of_posts_stays_within_the_requested_range(): void
    {
        Queue::fake();
        [$user, $account] = $this->userWithAccount();
        $this->scheduledPosts($user, $account, 12);

        app(AutoPublishScheduler::class)->rescheduleBulk(
            SocialPost::with('socialAccount')->get(),
            10 * 60,
            120 * 60,
            12, // raise the day cap so these all land without a rollover
        );

        $gaps = $this->sameDayGapsInMinutes($account);
        $this->assertNotEmpty($gaps);
        foreach ($gaps as $gap) {
            $this->assertLessThanOrEqual(120, $gap);
            $this->assertGreaterThanOrEqual(10, $gap);
        }
    }

    public function test_a_tight_range_is_honoured_exactly(): void
    {
        Queue::fake();
        [$user, $account] = $this->userWithAccount();
        $this->scheduledPosts($user, $account, 4);

        app(AutoPublishScheduler::class)->rescheduleBulk(
            SocialPost::with('socialAccount')->get(),
            15 * 60,
            20 * 60,
            10,
        );

        $gaps = $this->sameDayGapsInMinutes($account);
        $this->assertNotEmpty($gaps);
        foreach ($gaps as $gap) {
            $this->assertGreaterThanOrEqual(15, $gap);
            $this->assertLessThanOrEqual(20, $gap);
        }
    }

    public function test_gaps_are_not_all_identical(): void
    {
        Queue::fake();
        [$user, $account] = $this->userWithAccount();
        // Enough posts that saturating at the ceiling would be obvious if the
        // jitter were lost — a metronomic cadence is exactly what looks bot-like.
        $this->scheduledPosts($user, $account, 10);

        app(AutoPublishScheduler::class)->rescheduleBulk(
            SocialPost::with('socialAccount')->get(),
            10 * 60,
            120 * 60,
            10,
        );

        $gaps = $this->sameDayGapsInMinutes($account);
        $this->assertGreaterThan(1, count(array_unique($gaps)), 'Every gap was identical — the stagger lost its jitter.');
    }
}
