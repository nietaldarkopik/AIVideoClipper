<?php

namespace Tests\Feature\Research;

use App\Models\ContentChannel;
use App\Models\ContentIdea;
use App\Models\ContentIdeaSource;
use App\Models\Platform;
use App\Models\ResearchResult;
use App\Models\ResearchRun;
use App\Models\ResearchSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentIdeaApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ContentChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $platform = Platform::create(['key' => 'youtube', 'name' => 'YouTube', 'type' => 'video']);
        $this->user = User::factory()->create();
        $this->channel = ContentChannel::create([
            'user_id' => $this->user->id,
            'platform_id' => $platform->id,
            'name' => 'Test Channel',
            'niche' => 'Teknologi',
        ]);
    }

    private function idea(array $overrides = []): ContentIdea
    {
        return ContentIdea::create(array_merge([
            'content_channel_id' => $this->channel->id,
            'user_id' => $this->user->id,
            'research_date' => now()->toDateString(),
            'topic' => 'Topik',
            'title' => 'Judul Ide',
            'status' => ContentIdea::STATUS_IDEA,
            'priority_score' => 70,
            'trend_score' => 60,
        ], $overrides));
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/content-ideas')->assertUnauthorized();
    }

    public function test_lists_only_the_authenticated_users_ideas(): void
    {
        $this->idea(['title' => 'Milik Saya']);

        $other = User::factory()->create();
        $otherChannel = ContentChannel::create([
            'user_id' => $other->id,
            'platform_id' => $this->channel->platform_id,
            'name' => 'Other',
        ]);
        ContentIdea::create([
            'content_channel_id' => $otherChannel->id,
            'user_id' => $other->id,
            'research_date' => now()->toDateString(),
            'topic' => 'Rahasia', 'title' => 'Milik Orang Lain', 'status' => ContentIdea::STATUS_IDEA,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/content-ideas')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'Milik Saya');
    }

    public function test_ideas_are_ranked_by_priority_by_default(): void
    {
        $this->idea(['title' => 'Rendah', 'priority_score' => 20]);
        $this->idea(['title' => 'Tinggi', 'priority_score' => 95]);
        $this->idea(['title' => 'Sedang', 'priority_score' => 55]);

        $this->actingAs($this->user)->getJson('/api/content-ideas')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Tinggi')
            ->assertJsonPath('data.2.title', 'Rendah');
    }

    public function test_todays_research_is_never_buried_under_an_older_high_scoring_idea(): void
    {
        // Research accumulates day over day and is never replaced — an idea from a
        // previous day scoring higher must still surface BELOW today's batch, never
        // instead of it. Sorting by priority alone would let "Kemarin" sit on top
        // forever, which is indistinguishable from today's research having been
        // silently dropped.
        $this->idea(['title' => 'Kemarin', 'priority_score' => 99, 'research_date' => now()->subDay()->toDateString()]);
        $this->idea(['title' => 'Hari Ini', 'priority_score' => 40, 'research_date' => now()->toDateString()]);

        $this->actingAs($this->user)->getJson('/api/content-ideas')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Hari Ini')
            ->assertJsonPath('data.1.title', 'Kemarin');

        // Both days are present — accumulated, not replaced.
        $this->assertSame(2, ContentIdea::where('content_channel_id', $this->channel->id)->count());
    }

    public function test_a_channels_recent_ideas_widget_also_shows_todays_research_first(): void
    {
        $this->idea(['title' => 'Kemarin', 'priority_score' => 99, 'research_date' => now()->subDay()->toDateString()]);
        $this->idea(['title' => 'Hari Ini', 'priority_score' => 40, 'research_date' => now()->toDateString()]);

        $this->actingAs($this->user)->getJson("/api/content-channels/{$this->channel->id}/content-ideas")
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Hari Ini')
            ->assertJsonPath('data.1.title', 'Kemarin');
    }

    public function test_filters_by_channel_status_and_minimum_priority(): void
    {
        $this->idea(['title' => 'Prioritas Tinggi', 'priority_score' => 90]);
        $this->idea(['title' => 'Prioritas Rendah', 'priority_score' => 10]);
        // Low priority so it stays out of the min_priority assertion below and only
        // the status filter picks it up.
        $this->idea(['title' => 'Sudah Dipilih', 'status' => ContentIdea::STATUS_SELECTED, 'priority_score' => 5]);

        $this->actingAs($this->user)->getJson('/api/content-ideas?min_priority=50')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($this->user)->getJson('/api/content-ideas?status=selected')
            ->assertOk()->assertJsonPath('data.0.title', 'Sudah Dipilih');

        $this->actingAs($this->user)->getJson("/api/content-ideas?content_channel_id={$this->channel->id}")
            ->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_an_unknown_sort_value_falls_back_to_priority(): void
    {
        $this->idea(['title' => 'Rendah', 'priority_score' => 10]);
        $this->idea(['title' => 'Tinggi', 'priority_score' => 90]);

        // The sort parameter reaches ORDER BY, so anything outside the allowlist must
        // be ignored rather than passed through.
        $this->actingAs($this->user)->getJson('/api/content-ideas?sort=id;DROP TABLE content_ideas')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Tinggi');

        $this->assertSame(2, ContentIdea::count());
    }

    public function test_the_detail_view_returns_the_research_evidence(): void
    {
        $idea = $this->idea();

        ContentIdeaSource::create([
            'content_idea_id' => $idea->id,
            'source_key' => 'reddit',
            'source_title' => 'Diskusi asli di Reddit',
            'source_url' => 'https://www.reddit.com/r/programming/comments/abc',
            'engagement_metrics' => ['score' => 812, 'comments' => 240],
            'source_score' => 80,
        ]);

        $this->actingAs($this->user)->getJson("/api/content-ideas/{$idea->id}")
            ->assertOk()
            ->assertJsonPath('data.sources.0.source_key', 'reddit')
            ->assertJsonPath('data.sources.0.source_url', 'https://www.reddit.com/r/programming/comments/abc')
            ->assertJsonPath('data.sources.0.engagement_metrics.score', 812);
    }

    public function test_selecting_an_idea_stamps_the_time_and_does_not_advance_further(): void
    {
        $idea = $this->idea();

        $this->actingAs($this->user)->postJson("/api/content-ideas/{$idea->id}/select")
            ->assertOk()
            ->assertJsonPath('data.status', ContentIdea::STATUS_SELECTED);

        $idea->refresh();
        $this->assertNotNull($idea->selected_at);
        // This phase stops at "selected": nothing scripts, renders or publishes.
        $this->assertSame(ContentIdea::STATUS_SELECTED, $idea->status);
    }

    public function test_rejecting_keeps_the_idea_so_it_is_not_proposed_again(): void
    {
        $idea = $this->idea();

        $this->actingAs($this->user)->postJson("/api/content-ideas/{$idea->id}/reject")->assertOk();

        // Kept, not deleted — a rejected idea is exactly what the duplicate check needs.
        $this->assertSame(ContentIdea::STATUS_REJECTED, $idea->fresh()->status);
        $this->assertDatabaseHas('content_ideas', ['id' => $idea->id]);
    }

    public function test_an_invalid_status_is_rejected(): void
    {
        $idea = $this->idea();

        $this->actingAs($this->user)
            ->patchJson("/api/content-ideas/{$idea->id}", ['status' => 'invented_status'])
            ->assertUnprocessable();
    }

    public function test_another_users_idea_is_not_reachable(): void
    {
        $idea = $this->idea();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->getJson("/api/content-ideas/{$idea->id}")->assertNotFound();
        $this->actingAs($intruder)->postJson("/api/content-ideas/{$idea->id}/select")->assertNotFound();
        $this->assertSame(ContentIdea::STATUS_IDEA, $idea->fresh()->status);
    }

    public function test_the_dashboard_aggregates_the_users_research_state(): void
    {
        $this->idea(['priority_score' => 90]);
        $this->idea(['status' => ContentIdea::STATUS_SELECTED]);

        $this->actingAs($this->user)->getJson('/api/research/dashboard')
            ->assertOk()
            ->assertJsonPath('summary.channels_total', 1)
            ->assertJsonPath('summary.ideas_total', 2)
            ->assertJsonPath('summary.ideas_waiting_review', 1)
            ->assertJsonPath('summary.ideas_high_priority', 1)
            ->assertJsonStructure(['summary', 'ideas_by_channel', 'top_ideas', 'recent_runs', 'scheduler', 'provider_health', 'trending_topics']);
    }

    public function test_cross_channel_topics_from_earlier_days_stay_visible_dated_not_dropped(): void
    {
        // Topik Lintas Channel used to group by topic_key alone over a 48h window,
        // so a topic from a few days back silently fell out of this widget the
        // moment newer research came in — even though the underlying
        // research_results rows were never deleted. Grouping by day too (and
        // returning that date) is what proves each day's cross-channel topics
        // accumulate rather than get replaced.
        $run = ResearchRun::create(['content_channel_id' => $this->channel->id, 'user_id' => $this->user->id, 'trigger' => 'manual', 'status' => 'success']);

        $old = ResearchResult::create([
            'research_run_id' => $run->id, 'content_channel_id' => $this->channel->id,
            'source_key' => 'reddit', 'title' => 'Topik Lama', 'url' => 'https://example.com/old',
            'topic_key' => 'topik-lama',
        ]);
        $old->forceFill(['created_at' => now()->subDays(5)])->save();

        $recent = ResearchResult::create([
            'research_run_id' => $run->id, 'content_channel_id' => $this->channel->id,
            'source_key' => 'google_news', 'title' => 'Topik Baru', 'url' => 'https://example.com/new',
            'topic_key' => 'topik-baru',
        ]);
        $recent->forceFill(['created_at' => now()])->save();

        $response = $this->actingAs($this->user)->getJson('/api/research/dashboard')->assertOk();

        $topics = collect($response->json('trending_topics'));

        // Both days present — the older topic did not vanish just because newer
        // research exists.
        $this->assertTrue($topics->contains(fn ($t) => $t['topic_key'] === 'topik-lama'));
        $this->assertTrue($topics->contains(fn ($t) => $t['topic_key'] === 'topik-baru'));

        // Every entry carries its own research date, so the UI can show which day
        // each topic came from rather than presenting one undated blob.
        $this->assertNotNull($topics->firstWhere('topic_key', 'topik-lama')['research_date']);

        // Newest research day leads the list; the older one still trails behind it.
        $this->assertSame('topik-baru', $topics->first()['topic_key']);
    }

    public function test_the_source_registry_never_exposes_credentials(): void
    {
        ResearchSource::create([
            'key' => 'youtube', 'provider' => 'youtube', 'name' => 'YouTube', 'type' => 'video', 'enabled' => true,
        ]);

        config(['research.providers.youtube.api_key' => 'super-secret-key']);

        $response = $this->actingAs($this->user)->getJson('/api/research/sources')->assertOk();

        // Only WHETHER it is configured, never the credential itself.
        $response->assertJsonPath('data.0.requires_credentials', true);
        $response->assertJsonPath('data.0.is_configured', true);
        $this->assertStringNotContainsString('super-secret-key', $response->getContent());
    }

    public function test_configuring_a_global_source_is_admin_only(): void
    {
        $source = ResearchSource::create([
            'key' => 'reddit', 'provider' => 'reddit', 'name' => 'Reddit', 'type' => 'social', 'enabled' => true,
        ]);

        // research_sources is global state shared by every user's channels.
        $this->actingAs($this->user)
            ->patchJson("/api/admin/research/sources/{$source->id}", ['enabled' => false])
            ->assertForbidden();

        $this->assertTrue($source->fresh()->enabled);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)
            ->patchJson("/api/admin/research/sources/{$source->id}", ['enabled' => false])
            ->assertOk();

        $this->assertFalse($source->fresh()->enabled);
    }
}
