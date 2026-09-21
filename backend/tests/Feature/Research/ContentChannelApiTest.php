<?php

namespace Tests\Feature\Research;

use App\Models\ContentChannel;
use App\Models\Platform;
use App\Models\ResearchSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentChannelApiTest extends TestCase
{
    use RefreshDatabase;

    private function platform(array $overrides = []): Platform
    {
        return Platform::create(array_merge([
            'key' => 'youtube',
            'name' => 'YouTube',
            'type' => 'video',
            'default_strategy' => [
                'content_formats' => ['long_form', 'shorts'],
                'tone' => 'informatif',
            ],
        ], $overrides));
    }

    private function source(string $key = 'reddit'): ResearchSource
    {
        return ResearchSource::create([
            'key' => $key,
            'provider' => $key,
            'name' => ucfirst($key),
            'type' => 'social',
            'enabled' => true,
        ]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/content-channels')->assertUnauthorized();
    }

    public function test_creates_a_channel_from_the_api_without_any_code_change(): void
    {
        $platform = $this->platform();
        $source = $this->source();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/content-channels', [
            'platform_id' => $platform->id,
            'name' => 'AI Indonesia',
            'niche' => 'Artificial Intelligence',
            'sub_niches' => ['AI tools', 'AI news'],
            'keywords' => ['AI', 'ChatGPT', 'LLM'],
            'excluded_keywords' => ['judi'],
            'target_audience' => 'Indonesian technology enthusiasts',
            'language' => 'id',
            'timezone' => 'Asia/Jakarta',
            'content_style' => ['News', 'Tutorial'],
            'scheduler_enabled' => true,
            'research_frequency' => 'daily',
            'research_times' => ['06:00'],
            'ideas_per_run' => 5,
            'research_sources' => [
                ['research_source_id' => $source->id, 'weight' => 1.5, 'configuration' => ['subreddits' => ['artificial']]],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'AI Indonesia');
        $response->assertJsonPath('data.niche', 'Artificial Intelligence');
        $response->assertJsonPath('data.schedule_times.0', '06:00');

        $channel = ContentChannel::firstWhere('name', 'AI Indonesia');
        $this->assertSame(['AI', 'ChatGPT', 'LLM'], $channel->keywords);
        $this->assertSame(1, $channel->researchSources()->count());
        $this->assertSame(['artificial'], $channel->researchSources()->first()->pivot->configuration['subreddits']);

        // Enabling the scheduler at creation must put the channel on the schedule
        // immediately, not at whatever time the next hourly tick happens to notice it.
        $this->assertNotNull($channel->next_research_at);
    }

    public function test_platform_defaults_fill_only_unset_strategy_fields(): void
    {
        $platform = $this->platform();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/content-channels', [
            'platform_id' => $platform->id,
            'name' => 'Defaults Test',
            'content_formats' => ['carousel'],
        ])->assertCreated();

        $channel = ContentChannel::firstWhere('name', 'Defaults Test');

        // Explicit value survives; unset field is filled from the platform.
        $this->assertSame(['carousel'], $channel->content_formats);
        $this->assertSame('informatif', $channel->tone);
    }

    public function test_updating_the_schedule_recomputes_the_next_run(): void
    {
        $platform = $this->platform();
        $user = User::factory()->create();

        $channel = ContentChannel::create([
            'user_id' => $user->id,
            'platform_id' => $platform->id,
            'name' => 'Scheduled',
            'timezone' => 'Asia/Jakarta',
            'scheduler_enabled' => false,
        ]);

        $this->actingAs($user)->patchJson("/api/content-channels/{$channel->id}", [
            'scheduler_enabled' => true,
            'research_frequency' => 'custom',
            'research_times' => ['06:00', '18:00'],
        ])->assertOk();

        $channel->refresh();
        $this->assertTrue($channel->scheduler_enabled);
        $this->assertNotNull($channel->next_research_at);

        // Disabling clears it, so a paused channel can never be picked up by the tick.
        $this->actingAs($user)->patchJson("/api/content-channels/{$channel->id}", ['scheduler_enabled' => false])->assertOk();
        $this->assertNull($channel->refresh()->next_research_at);
    }

    public function test_rejects_a_malformed_research_time(): void
    {
        $platform = $this->platform();

        $this->actingAs(User::factory()->create())->postJson('/api/content-channels', [
            'platform_id' => $platform->id,
            'name' => 'Bad Time',
            // "6:00" would be silently dropped by scheduleTimes(), leaving the channel on
            // a default schedule the user never chose — so it must be rejected outright.
            'research_times' => ['6:00'],
        ])->assertUnprocessable();
    }

    public function test_a_channel_belonging_to_another_user_is_not_reachable(): void
    {
        $platform = $this->platform();
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $channel = ContentChannel::create([
            'user_id' => $owner->id,
            'platform_id' => $platform->id,
            'name' => 'Private',
        ]);

        $this->actingAs($intruder)->getJson("/api/content-channels/{$channel->id}")->assertNotFound();
        $this->actingAs($intruder)->patchJson("/api/content-channels/{$channel->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->assertSame('Private', $channel->fresh()->name);
    }

    public function test_configuring_sources_replaces_the_previous_selection(): void
    {
        $platform = $this->platform();
        $reddit = $this->source('reddit');
        $news = $this->source('google_news');
        $user = User::factory()->create();

        $channel = ContentChannel::create([
            'user_id' => $user->id,
            'platform_id' => $platform->id,
            'name' => 'Sources',
        ]);
        $channel->researchSources()->attach($reddit->id, ['enabled' => true, 'weight' => 1, 'priority' => 10]);

        $this->actingAs($user)->patchJson("/api/content-channels/{$channel->id}", [
            'research_sources' => [
                ['research_source_id' => $news->id, 'weight' => 2.0, 'configuration' => ['region' => 'ID']],
            ],
        ])->assertOk();

        $sources = $channel->fresh()->researchSources;
        $this->assertCount(1, $sources);
        $this->assertSame('google_news', $sources->first()->key);
        $this->assertSame(2.0, (float) $sources->first()->pivot->weight);
    }
}
