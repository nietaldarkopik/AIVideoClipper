<?php

namespace Tests\Feature;

use App\Models\Clip;
use App\Models\Project;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Covers POST /social-posts/bulk-move-channel — the "Move to Channel" bulk
 * action on the Scheduler page for fixing clips that were scheduled to the
 * wrong social account.
 */
class SocialPostBulkMoveChannelTest extends TestCase
{
    use RefreshDatabase;

    private function clip(User $user): Clip
    {
        $project = $user->projects()->create(['title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 20]);

        return Clip::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => Clip::STATUS_COMPLETED, 'output_path' => 'clips/x.mp4',
        ]);
    }

    public function test_moves_eligible_posts_to_the_target_account_and_restaggers_them(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $wrong = $user->socialAccounts()->create(['platform' => 'tiktok', 'account_name' => 'wrong', 'status' => SocialAccount::STATUS_CONNECTED]);
        $right = $user->socialAccounts()->create(['platform' => 'youtube', 'account_name' => 'right', 'status' => SocialAccount::STATUS_CONNECTED]);

        $clip1 = $this->clip($user);
        $clip2 = $this->clip($user);
        $post1 = SocialPost::create(['clip_id' => $clip1->id, 'social_account_id' => $wrong->id, 'platform' => 'tiktok', 'status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => now()->addHour()]);
        $post2 = SocialPost::create(['clip_id' => $clip2->id, 'social_account_id' => $wrong->id, 'platform' => 'tiktok', 'status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => now()->addHours(2)]);

        $response = $this->actingAs($user)->postJson('/api/social-posts/bulk-move-channel', [
            'social_post_ids' => [$post1->id, $post2->id],
            'target_social_account_id' => $right->id,
        ]);

        $response->assertOk();
        $this->assertSame(2, $response->json('moved_count'));

        $post1->refresh();
        $post2->refresh();
        $this->assertSame($right->id, $post1->social_account_id);
        $this->assertSame('youtube', $post1->platform);
        $this->assertSame($right->id, $post2->social_account_id);
        $this->assertSame('youtube', $post2->platform);
        // Restaggered onto the target account's own queue, not left at whatever
        // arbitrary time they had on the wrong account.
        $this->assertNotEquals($post1->scheduled_at->timestamp, now()->addHour()->timestamp);
    }

    public function test_skips_a_published_post(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $wrong = $user->socialAccounts()->create(['platform' => 'tiktok', 'account_name' => 'wrong', 'status' => SocialAccount::STATUS_CONNECTED]);
        $right = $user->socialAccounts()->create(['platform' => 'youtube', 'account_name' => 'right', 'status' => SocialAccount::STATUS_CONNECTED]);

        $clip = $this->clip($user);
        $published = SocialPost::create(['clip_id' => $clip->id, 'social_account_id' => $wrong->id, 'platform' => 'tiktok', 'status' => SocialPost::STATUS_PUBLISHED, 'scheduled_at' => now()->subHour()]);

        $response = $this->actingAs($user)->postJson('/api/social-posts/bulk-move-channel', [
            'social_post_ids' => [$published->id],
            'target_social_account_id' => $right->id,
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('moved_count'));
        $this->assertSame($wrong->id, $published->fresh()->social_account_id);
    }

    public function test_cancels_a_duplicate_instead_of_creating_a_second_post_for_the_same_clip_and_account(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $wrong = $user->socialAccounts()->create(['platform' => 'tiktok', 'account_name' => 'wrong', 'status' => SocialAccount::STATUS_CONNECTED]);
        $right = $user->socialAccounts()->create(['platform' => 'youtube', 'account_name' => 'right', 'status' => SocialAccount::STATUS_CONNECTED]);

        $clip = $this->clip($user);
        // Already has a (correct) post on the target account...
        $alreadyRight = SocialPost::create(['clip_id' => $clip->id, 'social_account_id' => $right->id, 'platform' => 'youtube', 'status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => now()->addHour()]);
        // ...and a stray duplicate on the wrong one, which the user selects to move.
        $stray = SocialPost::create(['clip_id' => $clip->id, 'social_account_id' => $wrong->id, 'platform' => 'tiktok', 'status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => now()->addHours(2)]);

        $response = $this->actingAs($user)->postJson('/api/social-posts/bulk-move-channel', [
            'social_post_ids' => [$stray->id],
            'target_social_account_id' => $right->id,
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('moved_count'));
        $this->assertSame(SocialPost::STATUS_CANCELLED, $stray->fresh()->status);
        $this->assertSame(SocialPost::STATUS_SCHEDULED, $alreadyRight->fresh()->status, 'the pre-existing correct post must be untouched');
    }

    public function test_cannot_move_another_users_post(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $strangerAccount = $stranger->socialAccounts()->create(['platform' => 'youtube', 'account_name' => 'a', 'status' => SocialAccount::STATUS_CONNECTED]);

        $clip = $this->clip($owner);
        $ownerAccount = $owner->socialAccounts()->create(['platform' => 'tiktok', 'account_name' => 'b', 'status' => SocialAccount::STATUS_CONNECTED]);
        $post = SocialPost::create(['clip_id' => $clip->id, 'social_account_id' => $ownerAccount->id, 'platform' => 'tiktok', 'status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => now()->addHour()]);

        $response = $this->actingAs($stranger)->postJson('/api/social-posts/bulk-move-channel', [
            'social_post_ids' => [$post->id],
            'target_social_account_id' => $strangerAccount->id,
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('moved_count'));
        $this->assertSame($ownerAccount->id, $post->fresh()->social_account_id);
    }
}
