<?php

namespace Tests\Feature;

use App\Models\Clip;
use App\Models\Project;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The scheduler's table view pages through this endpoint rather than taking a
 * fixed slice of the newest rows (it used to ask for 500 and silently hide
 * everything past that), so the paginator's meta and page/per_page handling are
 * what that whole view depends on.
 */
class SocialPostPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function seedPosts(User $user, int $count): SocialAccount
    {
        $account = $user->socialAccounts()->create([
            'platform' => 'tiktok', 'account_name' => 'acc', 'status' => SocialAccount::STATUS_CONNECTED,
        ]);
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
                'status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => now()->addHours($i + 1),
            ]);
        }

        return $account;
    }

    public function test_it_reports_paginator_meta_the_table_view_needs(): void
    {
        $user = User::factory()->create();
        $this->seedPosts($user, 12);

        $response = $this->actingAs($user)->getJson('/api/social-posts?per_page=5&status=scheduled');

        $response->assertOk();
        $this->assertSame(1, $response->json('meta.current_page'));
        $this->assertSame(3, $response->json('meta.last_page'));
        $this->assertSame(12, $response->json('meta.total'));
        $this->assertCount(5, $response->json('data'));
    }

    public function test_later_pages_return_the_following_rows_in_schedule_order(): void
    {
        $user = User::factory()->create();
        $this->seedPosts($user, 12);

        $firstPage = $this->actingAs($user)->getJson('/api/social-posts?per_page=5&page=1&status=scheduled');
        $secondPage = $this->actingAs($user)->getJson('/api/social-posts?per_page=5&page=2&status=scheduled');

        $firstIds = collect($firstPage->json('data'))->pluck('id');
        $secondIds = collect($secondPage->json('data'))->pluck('id');

        $this->assertSame(2, $secondPage->json('meta.current_page'));
        $this->assertCount(5, $secondIds);
        $this->assertEmpty($firstIds->intersect($secondIds), 'Pages must not repeat rows.');

        // Soonest-first ordering has to hold ACROSS pages, not just within one —
        // otherwise paging through the table would jump around in time.
        $firstPageLast = collect($firstPage->json('data'))->last()['scheduled_at'];
        $secondPageFirst = collect($secondPage->json('data'))->first()['scheduled_at'];
        $this->assertLessThanOrEqual($secondPageFirst, $firstPageLast);
    }

    public function test_a_page_past_the_end_returns_no_rows_but_still_reports_the_total(): void
    {
        $user = User::factory()->create();
        $this->seedPosts($user, 3);

        $response = $this->actingAs($user)->getJson('/api/social-posts?per_page=5&page=9&status=scheduled');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_pagination_only_counts_the_authenticated_users_posts(): void
    {
        $user = User::factory()->create();
        $this->seedPosts($user, 4);
        $this->seedPosts(User::factory()->create(), 7);

        $response = $this->actingAs($user)->getJson('/api/social-posts?per_page=5&status=scheduled');

        $this->assertSame(4, $response->json('meta.total'));
    }
}
