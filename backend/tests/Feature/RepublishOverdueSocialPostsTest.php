<?php

namespace Tests\Feature;

use App\Jobs\PublishClipJob;
use App\Models\Clip;
use App\Models\Project;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * AutoPublishScheduler dispatches PublishClipJob(...)->delay($slot) exactly
 * once, at schedule time — there is no database record of "this should have
 * fired by now", so a delayed job the queue driver drops (this app's real
 * trigger: a Redis restart with AOF off silently wipes anything not yet flushed
 * to an RDB snapshot) leaves the post at STATUS_SCHEDULED forever with nothing
 * to notice. This command is the reconciliation sweep.
 */
class RepublishOverdueSocialPostsTest extends TestCase
{
    use RefreshDatabase;

    private function scheduledPost(
        User $user,
        \Illuminate\Support\Carbon $scheduledAt,
        string $clipStatus = Clip::STATUS_COMPLETED,
        string $platform = 'youtube',
    ): SocialPost {
        $account = SocialAccount::create([
            'user_id' => $user->id, 'platform' => $platform,
            'account_name' => 'Ch', 'status' => SocialAccount::STATUS_CONNECTED,
        ]);
        $project = $user->projects()->create(['title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 20]);
        $clip = Clip::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => $clipStatus,
            'output_path' => $clipStatus === Clip::STATUS_COMPLETED ? 'clips/x.mp4' : null,
        ]);

        return SocialPost::create([
            'clip_id' => $clip->id, 'social_account_id' => $account->id, 'platform' => $platform,
            'status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => $scheduledAt,
        ]);
    }

    public function test_an_overdue_post_with_a_ready_clip_is_re_dispatched(): void
    {
        Bus::fake();
        $post = $this->scheduledPost(User::factory()->create(), now()->subMinutes(30));

        $this->artisan('social:republish-overdue')->assertSuccessful();

        Bus::assertDispatched(PublishClipJob::class, fn (PublishClipJob $job) => $job->socialPostId === $post->id);
    }

    public function test_a_post_still_within_its_grace_period_is_left_alone(): void
    {
        Bus::fake();
        // Barely late — within the default 10-minute grace, exactly the window a
        // delayed job that's actually still in flight would fall into.
        $this->scheduledPost(User::factory()->create(), now()->subMinutes(2));

        $this->artisan('social:republish-overdue')->assertSuccessful();

        Bus::assertNotDispatched(PublishClipJob::class);
    }

    public function test_an_overdue_post_whose_clip_is_not_ready_yet_waits(): void
    {
        Bus::fake();
        // Overdue for the same reason ReapStuckClipRendersTest covers — the
        // clip's own render job was lost. Publishing this now would just burn
        // PublishClipJob's 4 tries against a clip that legitimately isn't ready;
        // ReapStuckClipRenders is what fixes the clip, and the NEXT run of this
        // command picks the post back up once it's completed.
        $this->scheduledPost(User::factory()->create(), now()->subMinutes(30), Clip::STATUS_QUEUED);

        $this->artisan('social:republish-overdue')->assertSuccessful();

        Bus::assertNotDispatched(PublishClipJob::class);
    }

    public function test_a_future_or_already_published_post_is_never_touched(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $this->scheduledPost($user, now()->addHour());

        $published = $this->scheduledPost($user, now()->subMinutes(30));
        $published->update(['status' => SocialPost::STATUS_PUBLISHED]);

        $this->artisan('social:republish-overdue')->assertSuccessful();

        Bus::assertNotDispatched(PublishClipJob::class);
    }

    public function test_the_grace_period_is_configurable_via_the_option(): void
    {
        Bus::fake();
        $post = $this->scheduledPost(User::factory()->create(), now()->subMinutes(5));

        $this->artisan('social:republish-overdue', ['--minutes' => 3])->assertSuccessful();

        Bus::assertDispatched(PublishClipJob::class, fn (PublishClipJob $job) => $job->socialPostId === $post->id);
    }

    /**
     * Not exercised through the command directly — this locks down the
     * ShouldBeUnique contract on PublishClipJob itself, which is what actually
     * makes redispatching here safe. Without it, this command (or a manual
     * "Publish Now", or the original delayed slot firing late) could dispatch a
     * second instance for a post whose first attempt hasn't advanced its status
     * away from STATUS_SCHEDULED yet — and once more than one worker can pull
     * from the same queue, both would pass the status guards in PublishClipJob's
     * own handle() and genuinely upload the clip twice.
     */
    public function test_publish_clip_job_refuses_to_run_a_second_time_for_the_same_post_while_the_first_is_in_flight(): void
    {
        // 'twitter' is mock-backed (AbstractMockSocialProvider) so the unblocked
        // second dispatch below actually runs handle() to completion without a
        // real network call — it still exercises the same status-guard code path
        // PublishClipJob uses for every platform.
        \Illuminate\Support\Facades\Storage::fake('media');
        \Illuminate\Support\Facades\Storage::disk('media')->put('clips/x.mp4', 'fake-video-bytes');
        $post = $this->scheduledPost(User::factory()->create(), now()->subMinutes(30), platform: 'twitter');

        // Simulates "an instance for this post is already queued or executing" —
        // exactly Laravel's own ShouldBeUnique lock, acquired the same way
        // Illuminate\Bus\UniqueLock does internally.
        $lock = \Illuminate\Support\Facades\Cache::lock('laravel_unique_job:'.PublishClipJob::class.':'.$post->id, 900);
        $this->assertTrue($lock->get());

        PublishClipJob::dispatch($post->id);

        // Blocked at dispatch time — handle() never ran, so nothing about the
        // post changed from its pre-dispatch state.
        $this->assertSame(SocialPost::STATUS_SCHEDULED, $post->fresh()->status);

        $lock->release();
        PublishClipJob::dispatch($post->id);

        // Same dispatch, lock now free: proceeds and actually runs handle() this
        // time — proving the earlier block was the lock, not something else
        // wrong with the fixture.
        $this->assertNotSame(SocialPost::STATUS_SCHEDULED, $post->fresh()->status);
    }
}
