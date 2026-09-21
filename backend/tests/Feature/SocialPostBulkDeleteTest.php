<?php

namespace Tests\Feature;

use App\Models\Clip;
use App\Models\Project;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialPostBulkDeleteTest extends TestCase
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

    public function test_bulk_deletes_specified_posts_belonging_to_user(): void
    {
        $user = User::factory()->create();
        $account = $user->socialAccounts()->create(['platform' => 'tiktok', 'account_name' => 'acc', 'status' => SocialAccount::STATUS_CONNECTED]);

        $clip1 = $this->clip($user);
        $clip2 = $this->clip($user);
        $post1 = SocialPost::create(['clip_id' => $clip1->id, 'social_account_id' => $account->id, 'platform' => 'tiktok', 'status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => now()->addHour()]);
        $post2 = SocialPost::create(['clip_id' => $clip2->id, 'social_account_id' => $account->id, 'platform' => 'tiktok', 'status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => now()->addHours(2)]);

        $response = $this->actingAs($user)->postJson('/api/social-posts/bulk-delete', [
            'social_post_ids' => [$post1->id, $post2->id],
        ]);

        $response->assertOk();
        $this->assertSame(2, $response->json('deleted_count'));
        $this->assertDatabaseMissing('social_posts', ['id' => $post1->id]);
        $this->assertDatabaseMissing('social_posts', ['id' => $post2->id]);
    }

    public function test_does_not_delete_other_users_posts(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $ownerAccount = $owner->socialAccounts()->create(['platform' => 'tiktok', 'account_name' => 'owner', 'status' => SocialAccount::STATUS_CONNECTED]);
        $clip = $this->clip($owner);
        $post = SocialPost::create(['clip_id' => $clip->id, 'social_account_id' => $ownerAccount->id, 'platform' => 'tiktok', 'status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => now()->addHour()]);

        $response = $this->actingAs($stranger)->postJson('/api/social-posts/bulk-delete', [
            'social_post_ids' => [$post->id],
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('deleted_count'));
        $this->assertDatabaseHas('social_posts', ['id' => $post->id]);
    }
}
