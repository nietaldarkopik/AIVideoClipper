<?php

namespace Tests\Feature;

use App\Jobs\PublishClipJob;
use App\Models\ChannelWatch;
use App\Models\Clip;
use App\Models\Project;
use App\Models\PublishingProfile;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\VideoBatch;
use App\Models\VideoBatchItem;
use App\Services\Social\AutoPublishScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Covers the "fix a channel watch's wrong publishing target" flow:
 * ChannelWatchController::update() -> AutoPublishScheduler::resyncChannelWatchProfile()
 * when publishing_profile_id actually changes.
 */
class ChannelWatchResyncTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $user): Project
    {
        return $user->projects()->create(['title' => 'Resync test', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
    }

    private function completedClip(Project $project): Clip
    {
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 20]);

        return Clip::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => Clip::STATUS_COMPLETED, 'output_path' => 'clips/x.mp4',
        ]);
    }

    public function test_fixing_a_channel_watchs_publishing_profile_moves_its_pending_posts(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $wrongAccount = $user->socialAccounts()->create([
            'platform' => 'tiktok', 'account_name' => 'wrong', 'status' => SocialAccount::STATUS_CONNECTED, 'auto_publish_enabled' => true,
        ]);
        $rightAccount = $user->socialAccounts()->create([
            'platform' => 'youtube', 'account_name' => 'right', 'status' => SocialAccount::STATUS_CONNECTED, 'auto_publish_enabled' => true,
        ]);

        $wrongProfile = $user->publishingProfiles()->create(['name' => 'Wrong']);
        $wrongProfile->socialAccounts()->attach($wrongAccount->id);
        $rightProfile = $user->publishingProfiles()->create(['name' => 'Right']);
        $rightProfile->socialAccounts()->attach($rightAccount->id);

        $watch = $user->channelWatches()->create([
            'platform' => 'youtube', 'channel_id' => 'UCabc', 'channel_title' => 'Some Channel',
            'channel_url' => 'https://youtube.com/@some', 'is_active' => true,
            'settings' => ['publishing_profile_id' => $wrongProfile->id],
            'last_video_published_at' => now(),
        ]);

        $batch = VideoBatch::create([
            'user_id' => $user->id, 'channel_watch_id' => $watch->id,
            'name' => 'Auto: Some Channel', 'status' => VideoBatch::STATUS_COMPLETED, 'total_items' => 1,
        ]);
        $project = $this->project($user);
        VideoBatchItem::create([
            'video_batch_id' => $batch->id, 'position' => 0, 'source_url' => 'https://x.test/a',
            'status' => VideoBatchItem::STATUS_COMPLETED, 'project_id' => $project->id,
        ]);
        $clip = $this->completedClip($project);

        // Simulate the original (wrong-target) auto-schedule that already happened.
        app(AutoPublishScheduler::class)->scheduleForProject($project, $wrongProfile->id);
        $originalPost = SocialPost::where('clip_id', $clip->id)->where('social_account_id', $wrongAccount->id)->firstOrFail();
        $this->assertSame(SocialPost::STATUS_SCHEDULED, $originalPost->status);

        // A second, already-published post (e.g. a different clip that already
        // went out under the old target) must survive untouched.
        $publishedClip = $this->completedClip($project);
        $publishedPost = SocialPost::create([
            'clip_id' => $publishedClip->id, 'social_account_id' => $wrongAccount->id, 'platform' => 'tiktok',
            'status' => SocialPost::STATUS_PUBLISHED, 'scheduled_at' => now()->subHour(),
        ]);

        // Fix the channel's publishing target.
        $response = $this->actingAs($user)->patchJson("/api/channel-watches/{$watch->id}", [
            'publishing_profile_id' => $rightProfile->id,
        ]);
        $response->assertOk();
        $this->assertGreaterThan(0, $response->json('resynced_posts'));

        $this->assertSame(SocialPost::STATUS_CANCELLED, $originalPost->fresh()->status);

        $newPost = SocialPost::where('clip_id', $clip->id)->where('social_account_id', $rightAccount->id)->first();
        $this->assertNotNull($newPost, 'a fresh post for the corrected account should have been created');
        $this->assertSame(SocialPost::STATUS_SCHEDULED, $newPost->status);

        $this->assertSame(SocialPost::STATUS_PUBLISHED, $publishedPost->fresh()->status, 'an already-published post must never be touched');

        Queue::assertPushed(PublishClipJob::class, fn (PublishClipJob $job) => $job->socialPostId === $newPost->id);
    }

    public function test_updating_unrelated_fields_does_not_trigger_a_resync(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $account = $user->socialAccounts()->create([
            'platform' => 'tiktok', 'account_name' => 'a', 'status' => SocialAccount::STATUS_CONNECTED, 'auto_publish_enabled' => true,
        ]);
        $profile = $user->publishingProfiles()->create(['name' => 'P']);
        $profile->socialAccounts()->attach($account->id);

        $watch = $user->channelWatches()->create([
            'platform' => 'youtube', 'channel_id' => 'UCabc', 'channel_title' => 'Some Channel',
            'channel_url' => 'https://youtube.com/@some', 'is_active' => true,
            'settings' => ['publishing_profile_id' => $profile->id],
            'last_video_published_at' => now(),
        ]);

        $project = $this->project($user);
        VideoBatchItem::create([
            'video_batch_id' => VideoBatch::create(['user_id' => $user->id, 'channel_watch_id' => $watch->id, 'total_items' => 1])->id,
            'position' => 0, 'source_url' => 'https://x.test/a', 'status' => VideoBatchItem::STATUS_COMPLETED, 'project_id' => $project->id,
        ]);
        $clip = $this->completedClip($project);
        app(AutoPublishScheduler::class)->scheduleForProject($project, $profile->id);
        $post = SocialPost::where('clip_id', $clip->id)->firstOrFail();

        $response = $this->actingAs($user)->patchJson("/api/channel-watches/{$watch->id}", [
            'clip_mode' => 'top_3',
        ]);
        $response->assertOk();
        $this->assertSame(0, $response->json('resynced_posts'));
        $this->assertSame(SocialPost::STATUS_SCHEDULED, $post->fresh()->status);
    }
}
