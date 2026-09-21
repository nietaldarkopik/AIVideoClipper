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
 * The scheduler table sorts server-side, because it pages through the full
 * result set — sorting the page the client already holds would only ever
 * reorder the 25 rows on screen.
 */
class SocialPostSortingTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(User $user, string $accountName, string $status, ?string $scheduledAt, ?string $publishedAt = null): SocialPost
    {
        $account = SocialAccount::firstOrCreate(
            ['user_id' => $user->id, 'account_name' => $accountName],
            ['platform' => 'tiktok', 'status' => SocialAccount::STATUS_CONNECTED],
        );

        $project = $user->projects()->create([
            'title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now(),
        ]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 20]);
        $clip = Clip::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => Clip::STATUS_COMPLETED, 'output_path' => 'clips/x.mp4',
        ]);

        return SocialPost::create([
            'clip_id' => $clip->id, 'social_account_id' => $account->id, 'platform' => 'tiktok',
            'status' => $status, 'scheduled_at' => $scheduledAt, 'published_at' => $publishedAt,
        ]);
    }

    private function sorted(User $user, string $by, string $dir, string $field = 'id'): array
    {
        $response = $this->actingAs($user)->getJson("/api/social-posts?per_page=50&sort_by={$by}&sort_dir={$dir}");
        $response->assertOk();

        return collect($response->json('data'))->pluck($field)->all();
    }

    public function test_it_sorts_by_channel_name(): void
    {
        $user = User::factory()->create();
        $this->makePost($user, 'Zebra Channel', 'scheduled', '2026-01-01 10:00:00');
        $this->makePost($user, 'Alpha Channel', 'scheduled', '2026-01-02 10:00:00');
        $this->makePost($user, 'Mid Channel', 'scheduled', '2026-01-03 10:00:00');

        $names = collect($this->actingAs($user)->getJson('/api/social-posts?sort_by=channel&sort_dir=asc')->json('data'))
            ->pluck('social_account.account_name')->all();

        $this->assertSame(['Alpha Channel', 'Mid Channel', 'Zebra Channel'], $names);
    }

    public function test_it_sorts_by_status(): void
    {
        $user = User::factory()->create();
        $this->makePost($user, 'A', 'scheduled', '2026-01-01 10:00:00');
        $this->makePost($user, 'A', 'cancelled', '2026-01-02 10:00:00');
        $this->makePost($user, 'A', 'failed', '2026-01-03 10:00:00');

        $this->assertSame(['cancelled', 'failed', 'scheduled'], $this->sorted($user, 'status', 'asc', 'status'));
        $this->assertSame(['scheduled', 'failed', 'cancelled'], $this->sorted($user, 'status', 'desc', 'status'));
    }

    public function test_unpublished_posts_sort_last_regardless_of_direction(): void
    {
        $user = User::factory()->create();
        $early = $this->makePost($user, 'A', 'published', '2026-01-01 10:00:00', '2026-01-01 11:00:00');
        $late = $this->makePost($user, 'A', 'published', '2026-01-02 10:00:00', '2026-01-02 11:00:00');
        $never = $this->makePost($user, 'A', 'scheduled', '2026-01-03 10:00:00', null);

        // "Never published" is an absence, not a date — it must not masquerade
        // as the oldest publish time just because NULL sorts first.
        $this->assertSame([$early->id, $late->id, $never->id], $this->sorted($user, 'published_at', 'asc'));
        $this->assertSame([$late->id, $early->id, $never->id], $this->sorted($user, 'published_at', 'desc'));
    }

    public function test_unscheduled_posts_sort_last_by_default(): void
    {
        $user = User::factory()->create();
        $scheduled = $this->makePost($user, 'A', 'scheduled', '2026-01-02 10:00:00');
        $unscheduled = $this->makePost($user, 'A', 'ready', null);

        $this->assertSame([$scheduled->id, $unscheduled->id], $this->sorted($user, 'scheduled_at', 'asc'));
    }

    public function test_rows_sharing_a_sort_value_do_not_repeat_or_vanish_across_pages(): void
    {
        $user = User::factory()->create();
        // Every row has the identical status, so only the tiebreaker keeps the
        // order stable — without one, paging can show a row twice and skip
        // another entirely.
        for ($i = 0; $i < 10; $i++) {
            $this->makePost($user, 'A', 'scheduled', '2026-01-01 10:00:00');
        }

        $ids = [];
        foreach ([1, 2] as $page) {
            $ids = array_merge($ids, collect(
                $this->actingAs($user)
                    ->getJson("/api/social-posts?per_page=5&page={$page}&sort_by=status&sort_dir=asc")
                    ->json('data')
            )->pluck('id')->all());
        }

        $this->assertCount(10, $ids);
        $this->assertCount(10, array_unique($ids), 'A row appeared on more than one page.');
    }

    public function test_an_unknown_sort_key_falls_back_to_the_schedule_order(): void
    {
        $user = User::factory()->create();
        $second = $this->makePost($user, 'A', 'scheduled', '2026-01-02 10:00:00');
        $first = $this->makePost($user, 'A', 'scheduled', '2026-01-01 10:00:00');

        $this->assertSame([$first->id, $second->id], $this->sorted($user, 'nonsense_column', 'asc'));
    }
}
