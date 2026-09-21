<?php

namespace Tests\Feature;

use App\Models\Clip;
use App\Models\Project;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostMetricSnapshot;
use App\Models\User;
use App\Services\Social\SocialMetricsSyncer;
use App\Services\Social\SocialTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every SocialProvider has always implemented fetchMetrics(), but nothing called
 * it — so `metrics` stayed null forever and the app could publish content without
 * ever learning which content worked. These cover the piece that closes that
 * loop: which posts come due (SocialMetricsSyncer's staleness tiers), what a sync
 * writes, and what /api/analytics reports back.
 */
class SocialMetricsSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(
        User $user,
        string $status = SocialPost::STATUS_PUBLISHED,
        ?string $publishedAt = '-2 hours',
        ?string $metricsSyncedAt = null,
        string $accountStatus = SocialAccount::STATUS_CONNECTED,
        string $platform = 'tiktok',
        ?array $metrics = null,
    ): SocialPost {
        $account = SocialAccount::create([
            'user_id' => $user->id,
            'platform' => $platform,
            'account_name' => 'Channel',
            'status' => $accountStatus,
        ]);

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
            'clip_id' => $clip->id,
            'social_account_id' => $account->id,
            'platform' => $platform,
            'status' => $status,
            'external_post_id' => 'ext-'.uniqid(),
            'published_at' => $publishedAt ? now()->parse($publishedAt) : null,
            'metrics' => $metrics,
            'metrics_synced_at' => $metricsSyncedAt ? now()->parse($metricsSyncedAt) : null,
        ]);
    }

    private function syncer(): SocialMetricsSyncer
    {
        return app(SocialMetricsSyncer::class);
    }

    public function test_a_never_synced_published_post_is_due(): void
    {
        $post = $this->makePost(User::factory()->create());

        $this->assertTrue($this->syncer()->dueQuery()->pluck('id')->contains($post->id));
    }

    public function test_a_post_synced_minutes_ago_is_not_due_again(): void
    {
        // Inside the 60-minute interval of the freshest tier: re-querying it would
        // spend an API call to learn nothing.
        $post = $this->makePost(User::factory()->create(), metricsSyncedAt: '-10 minutes');

        $this->assertFalse($this->syncer()->dueQuery()->pluck('id')->contains($post->id));
    }

    public function test_an_older_post_uses_a_slower_tier(): void
    {
        $user = User::factory()->create();
        // A week old, synced two hours ago — past the fresh tier's hour, but well
        // inside the 6-hour interval that applies at this age.
        $weekOld = $this->makePost($user, publishedAt: '-7 days', metricsSyncedAt: '-2 hours');
        // Same age, but last synced eight hours ago: now genuinely stale.
        $stale = $this->makePost($user, publishedAt: '-7 days', metricsSyncedAt: '-8 hours');

        $due = $this->syncer()->dueQuery()->pluck('id');

        $this->assertFalse($due->contains($weekOld->id));
        $this->assertTrue($due->contains($stale->id));
    }

    public function test_posts_older_than_the_last_tier_stop_being_synced(): void
    {
        // Past 60 days the numbers have effectively stopped moving, and a growing
        // back catalogue would otherwise crowd out the posts that matter.
        $ancient = $this->makePost(User::factory()->create(), publishedAt: '-100 days', metricsSyncedAt: '-40 days');

        $this->assertFalse($this->syncer()->dueQuery()->pluck('id')->contains($ancient->id));
    }

    public function test_unpublished_posts_and_disconnected_accounts_are_skipped(): void
    {
        $user = User::factory()->create();
        $scheduled = $this->makePost($user, status: SocialPost::STATUS_SCHEDULED, publishedAt: null);
        $expiredAccount = $this->makePost($user, accountStatus: SocialAccount::STATUS_EXPIRED);

        $due = $this->syncer()->dueQuery()->pluck('id');

        $this->assertFalse($due->contains($scheduled->id));
        $this->assertFalse($due->contains($expiredAccount->id));
    }

    public function test_syncing_writes_metrics_and_the_timestamp(): void
    {
        // 'twitter' is one of the two platforms still backed by
        // AbstractMockSocialProvider, so this exercises the write path without a
        // network call or a fake to maintain. The real HTTP providers' own error
        // path is covered separately below.
        $post = $this->makePost(User::factory()->create(), platform: 'twitter');

        $this->assertTrue($this->syncer()->syncOne($post));

        $post->refresh();
        $this->assertNotNull($post->metrics_synced_at);
        $this->assertArrayHasKey('views', $post->metrics);
    }

    public function test_the_manual_sync_endpoint_returns_the_refreshed_post(): void
    {
        $user = User::factory()->create();
        $post = $this->makePost($user, platform: 'twitter');

        $response = $this->actingAs($user)->postJson("/api/social-posts/{$post->id}/sync-metrics");

        $response->assertOk();
        // The response carries the new numbers so the caller can render them
        // directly instead of firing a second request to read them back.
        $this->assertNotNull($response->json('data.metrics_synced_at'));
        $this->assertArrayHasKey('views', $response->json('data.metrics'));
    }

    public function test_a_failed_fetch_keeps_the_previous_numbers_and_stays_due(): void
    {
        // YouTube is a real HTTP provider, so a faked failure exercises the actual
        // error path rather than the mock provider's always-succeeds one.
        Http::fake(['*' => Http::response('nope', 500)]);

        $post = $this->makePost(
            User::factory()->create(),
            metricsSyncedAt: '-3 hours',
            platform: 'youtube',
            metrics: ['views' => 999, 'likes' => 10, 'comments' => 0, 'shares' => 0, 'saves' => 0],
        );

        $this->assertFalse($this->syncer()->syncOne($post));

        $post->refresh();
        // A transient API error must never blank out numbers the UI is showing...
        $this->assertSame(999, $post->metrics['views']);
        // ...nor mark the post as freshly synced, or it would wait out a whole
        // tier interval before anything tried again.
        $this->assertTrue($post->metrics_synced_at->isBefore(now()->subHours(2)));
        $this->assertTrue($this->syncer()->dueQuery()->pluck('id')->contains($post->id));
    }

    public function test_analytics_aggregates_only_the_callers_own_posts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->makePost($user, metrics: ['views' => 100, 'likes' => 10, 'comments' => 5, 'shares' => 0, 'saves' => 0]);
        $this->makePost($user, metrics: ['views' => 50, 'likes' => 5, 'comments' => 0, 'shares' => 0, 'saves' => 0]);
        $this->makePost($other, metrics: ['views' => 9999, 'likes' => 999, 'comments' => 0, 'shares' => 0, 'saves' => 0]);

        $response = $this->actingAs($user)->getJson('/api/analytics?days=30');

        $response->assertOk();
        $this->assertSame(2, $response->json('totals.posts'));
        $this->assertSame(150, $response->json('totals.views'));
        $this->assertSame(15, $response->json('totals.likes'));
        // (10 + 5 + 5 + 0) / 150 — reported the way each platform reports it.
        $this->assertSame(13.33, $response->json('totals.engagement_rate'));
    }

    public function test_analytics_excludes_posts_outside_the_window(): void
    {
        $user = User::factory()->create();
        $this->makePost($user, publishedAt: '-2 days', metrics: ['views' => 100]);
        $this->makePost($user, publishedAt: '-40 days', metrics: ['views' => 5000]);

        $response = $this->actingAs($user)->getJson('/api/analytics?days=7');

        $response->assertOk();
        $this->assertSame(1, $response->json('totals.posts'));
        $this->assertSame(100, $response->json('totals.views'));
    }

    public function test_analytics_ranks_top_posts_and_ignores_unsynced_ones(): void
    {
        $user = User::factory()->create();
        $this->makePost($user, metrics: ['views' => 10]);
        $best = $this->makePost($user, metrics: ['views' => 900]);
        // Never synced: unknown, not zero — including it would imply we measured
        // it and found nothing.
        $this->makePost($user);

        $top = $this->actingAs($user)->getJson('/api/analytics')->json('top_posts');

        $this->assertCount(2, $top);
        $this->assertSame($best->id, $top[0]['id']);
    }

    public function test_manual_sync_endpoint_rejects_posts_that_have_no_metrics(): void
    {
        $user = User::factory()->create();
        $scheduled = $this->makePost($user, status: SocialPost::STATUS_SCHEDULED, publishedAt: null);

        $this->actingAs($user)
            ->postJson("/api/social-posts/{$scheduled->id}/sync-metrics")
            ->assertStatus(422);
    }

    public function test_manual_sync_endpoint_is_scoped_to_the_owner(): void
    {
        $post = $this->makePost(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->postJson("/api/social-posts/{$post->id}/sync-metrics")
            ->assertNotFound();
    }

    public function test_a_sync_records_one_snapshot_per_day_overwriting_earlier_readings(): void
    {
        $post = $this->makePost(User::factory()->create(), platform: 'twitter');

        $this->syncer()->syncOne($post);
        $this->syncer()->syncOne($post);

        // Fresh posts sync hourly; keeping every reading would store ~24 rows a day
        // to express what the day's final figure already says.
        $snapshots = SocialPostMetricSnapshot::where('social_post_id', $post->id)->get();
        $this->assertCount(1, $snapshots);
        $this->assertSame((int) $post->fresh()->metrics['views'], $snapshots->first()->views);
    }

    public function test_the_trend_reports_views_gained_per_day_not_running_totals(): void
    {
        $user = User::factory()->create();
        $post = $this->makePost($user, metrics: ['views' => 300]);

        // Cumulative readings, the way the platforms report them.
        foreach ([['-3 days', 100], ['-2 days', 180], ['-1 day', 300]] as [$day, $views]) {
            SocialPostMetricSnapshot::create([
                'social_post_id' => $post->id,
                'captured_on' => now()->parse($day)->toDateString(),
                'views' => $views,
            ]);
        }

        $series = collect($this->actingAs($user)->getJson('/api/analytics?days=7')->json('timeseries'))
            ->keyBy('date');

        // First reading in the window counts in full, later ones only their growth —
        // summing the cumulative figures instead would produce a line that only ever
        // climbs and dips when a post ages out of syncing.
        $this->assertSame(100, $series[now()->subDays(3)->toDateString()]['views']);
        $this->assertSame(80, $series[now()->subDays(2)->toDateString()]['views']);
        $this->assertSame(120, $series[now()->subDay()->toDateString()]['views']);
    }

    public function test_the_trend_never_reports_a_negative_day(): void
    {
        $user = User::factory()->create();
        $post = $this->makePost($user, metrics: ['views' => 90]);

        // Platforms do revise counts downward (spam removal, deleted comments); a
        // negative day would read as losing views.
        SocialPostMetricSnapshot::create(['social_post_id' => $post->id, 'captured_on' => now()->subDays(2)->toDateString(), 'views' => 500]);
        SocialPostMetricSnapshot::create(['social_post_id' => $post->id, 'captured_on' => now()->subDay()->toDateString(), 'views' => 400]);

        $series = collect($this->actingAs($user)->getJson('/api/analytics?days=7')->json('timeseries'));

        $this->assertSame(0, $series->firstWhere('date', now()->subDay()->toDateString())['views']);
        $this->assertTrue($series->every(fn ($day) => $day['views'] >= 0));
    }

    public function test_the_trend_covers_every_day_in_the_window(): void
    {
        $user = User::factory()->create();
        $this->makePost($user, metrics: ['views' => 10]);

        $series = $this->actingAs($user)->getJson('/api/analytics?days=7')->json('timeseries');

        // An even x-axis: days with no sync are present at zero rather than absent,
        // which would otherwise compress the chart's time scale.
        $this->assertCount(7, $series);
    }

    public function test_snapshots_are_removed_with_their_post(): void
    {
        $post = $this->makePost(User::factory()->create(), platform: 'twitter');
        $this->syncer()->syncOne($post);

        $post->delete();

        $this->assertSame(0, SocialPostMetricSnapshot::where('social_post_id', $post->id)->count());
    }

    public function test_only_accounts_near_expiry_are_queued_for_a_token_refresh(): void
    {
        $user = User::factory()->create();

        $expiringSoon = SocialAccount::create([
            'user_id' => $user->id, 'platform' => 'youtube', 'account_name' => 'Soon',
            'status' => SocialAccount::STATUS_CONNECTED, 'refresh_token' => 'r',
            'token_expires_at' => now()->addHour(),
        ]);
        $plentyOfTime = SocialAccount::create([
            'user_id' => $user->id, 'platform' => 'youtube', 'account_name' => 'Later',
            'status' => SocialAccount::STATUS_CONNECTED, 'refresh_token' => 'r',
            'token_expires_at' => now()->addDays(5),
        ]);
        // No refresh token means there's nothing to exchange — this one needs a
        // reconnect from the UI, and retrying it hourly would achieve nothing.
        $noRefreshToken = SocialAccount::create([
            'user_id' => $user->id, 'platform' => 'youtube', 'account_name' => 'Stuck',
            'status' => SocialAccount::STATUS_CONNECTED,
            'token_expires_at' => now()->addHour(),
        ]);
        // Already expired/revoked: a refresh can't fix a grant the user withdrew.
        $revoked = SocialAccount::create([
            'user_id' => $user->id, 'platform' => 'youtube', 'account_name' => 'Gone',
            'status' => SocialAccount::STATUS_REVOKED, 'refresh_token' => 'r',
            'token_expires_at' => now()->addHour(),
        ]);

        $due = app(SocialTokenRefresher::class)->dueQuery()->pluck('id');

        $this->assertTrue($due->contains($expiringSoon->id));
        $this->assertFalse($due->contains($plentyOfTime->id));
        $this->assertFalse($due->contains($noRefreshToken->id));
        $this->assertFalse($due->contains($revoked->id));
    }
}
