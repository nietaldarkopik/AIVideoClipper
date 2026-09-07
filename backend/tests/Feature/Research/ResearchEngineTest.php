<?php

namespace Tests\Feature\Research;

use App\Models\ContentChannel;
use App\Models\ContentIdea;
use App\Models\Platform;
use App\Models\ResearchRun;
use App\Models\ResearchSource;
use App\Models\User;
use App\Services\Research\ChannelResearchEngine;
use App\Services\Research\Contracts\ResearchProvider;
use App\Services\Research\DTOs\ProviderHealth;
use App\Services\Research\DTOs\ResearchItem;
use App\Services\Research\DTOs\ResearchQuery;
use App\Services\Research\ResearchProviderManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Exercises the pipeline with stub providers, so no test touches the network.
 * The stubs are registered through ResearchProviderManager exactly the way a real
 * provider is — which is itself the proof that adding a provider needs no engine
 * change.
 */
class ResearchEngineTest extends TestCase
{
    use RefreshDatabase;

    private function registerProviders(ResearchProvider ...$providers): void
    {
        $map = [];

        foreach ($providers as $provider) {
            // Bound under a per-key container alias rather than ::class — every stub
            // shares one PHP class, so binding by class would make the last one
            // registered resolve for all of them. The manager only ever calls
            // app($identifier), so an alias works exactly like a class-string here.
            $alias = 'test-research-provider.'.$provider->key();
            $this->app->instance($alias, $provider);
            $map[$provider->key()] = $alias;
        }

        $this->app->instance(ResearchProviderManager::class, new ResearchProviderManager($map));
    }

    private function channelWith(array $sourceKeys, array $overrides = []): ContentChannel
    {
        $platform = Platform::create(['key' => 'youtube', 'name' => 'YouTube', 'type' => 'video']);
        $user = User::factory()->create();

        $channel = ContentChannel::create(array_merge([
            'user_id' => $user->id,
            'platform_id' => $platform->id,
            'name' => 'Test Channel',
            'language' => 'id',
            'timezone' => 'Asia/Jakarta',
            'niche' => 'Teknologi',
            'keywords' => ['docker', 'linux'],
            'ideas_per_run' => 3,
        ], $overrides));

        foreach ($sourceKeys as $index => $key) {
            $source = ResearchSource::create([
                'key' => $key, 'provider' => $key, 'name' => ucfirst($key), 'type' => 'general', 'enabled' => true,
            ]);
            $channel->researchSources()->attach($source->id, [
                'enabled' => true, 'weight' => 1.0, 'priority' => ($index + 1) * 10, 'configuration' => [],
            ]);
        }

        return $channel->fresh();
    }

    private function runEngine(ContentChannel $channel): ResearchRun
    {
        $run = ResearchRun::create([
            'content_channel_id' => $channel->id,
            'user_id' => $channel->user_id,
            'trigger' => 'manual',
            'status' => ResearchRun::STATUS_RUNNING,
        ]);

        return app(ChannelResearchEngine::class)->run($run);
    }

    public function test_a_successful_run_produces_ideas_with_real_source_evidence(): void
    {
        $this->registerProviders(new StubResearchProvider('stub_a', [
            ['Docker 27 dirilis dengan fitur baru', 'https://example.com/docker-27'],
            ['Linux kernel 6.12 masuk LTS', 'https://example.com/linux-612'],
        ]));

        $channel = $this->channelWith(['stub_a']);
        $run = $this->runEngine($channel);

        $this->assertSame(ResearchRun::STATUS_SUCCESS, $run->status);
        $this->assertGreaterThan(0, $run->ideas_generated);

        $idea = ContentIdea::where('content_channel_id', $channel->id)->firstOrFail();

        // Every idea must keep the evidence that produced it, with the real URL.
        $this->assertGreaterThan(0, $idea->sources()->count());
        $this->assertStringStartsWith('https://example.com/', $idea->sources()->first()->source_url);
        $this->assertSame($channel->user_id, $idea->user_id);
    }

    public function test_one_provider_failing_does_not_stop_the_others_and_marks_the_run_partial(): void
    {
        $this->registerProviders(
            new StubResearchProvider('stub_ok', [['Topik yang berhasil diambil', 'https://example.com/ok']]),
            new StubResearchProvider('stub_bad', [], throws: true),
        );

        $channel = $this->channelWith(['stub_ok', 'stub_bad']);
        $run = $this->runEngine($channel);

        $this->assertSame(ResearchRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(['stub_ok'], array_column($run->providers_used, 'source_key'));
        $this->assertSame('stub_bad', $run->providers_failed[0]['source_key']);
        // The working source still produced results.
        $this->assertGreaterThan(0, $run->results_collected);

        // The failure is recorded against the source itself, which is what eventually
        // opens its circuit breaker.
        $this->assertSame(1, ResearchSource::firstWhere('key', 'stub_bad')->consecutive_failures);
    }

    public function test_every_provider_failing_is_a_failed_run_not_a_partial_one(): void
    {
        $this->registerProviders(new StubResearchProvider('stub_bad', [], throws: true));

        $run = $this->runEngine($this->channelWith(['stub_bad']));

        $this->assertSame(ResearchRun::STATUS_FAILED, $run->status);
    }

    public function test_a_source_with_an_open_failure_circuit_is_skipped_with_a_reason(): void
    {
        $this->registerProviders(new StubResearchProvider('stub_a', [['Sesuatu', 'https://example.com/a']]));

        $channel = $this->channelWith(['stub_a']);
        ResearchSource::firstWhere('key', 'stub_a')->update([
            'consecutive_failures' => ResearchSource::FAILURE_CIRCUIT_THRESHOLD,
        ]);

        $run = $this->runEngine($channel);

        $this->assertSame([], $run->providers_used);
        $this->assertTrue($run->providers_failed[0]['skipped']);
        $this->assertStringContainsString('kegagalan berturut-turut', $run->providers_failed[0]['error']);
    }

    public function test_a_provider_missing_credentials_is_skipped_rather_than_counted_as_a_failure(): void
    {
        $this->registerProviders(new StubResearchProvider('stub_key', [], requiresCredentials: true, configured: false));

        $channel = $this->channelWith(['stub_key']);
        $this->runEngine($channel);

        // Skipping must NOT increment the failure counter, or a source the user simply
        // has not set up yet would trip its own circuit breaker.
        $this->assertSame(0, ResearchSource::firstWhere('key', 'stub_key')->consecutive_failures);
    }

    public function test_results_from_several_sources_about_one_topic_are_correlated_into_a_single_idea(): void
    {
        $shared = 'Docker Desktop mengalami kerentanan keamanan besar';

        $this->registerProviders(
            new StubResearchProvider('stub_a', [[$shared, 'https://a.example.com/1']]),
            new StubResearchProvider('stub_b', [[$shared.' menurut laporan', 'https://b.example.com/1']]),
            new StubResearchProvider('stub_c', [[$shared.' kata peneliti', 'https://c.example.com/1']]),
        );

        $channel = $this->channelWith(['stub_a', 'stub_b', 'stub_c']);
        $run = $this->runEngine($channel);

        // Three sources, one topic — not three separate topics.
        $this->assertSame(1, $run->topics_found);

        $idea = ContentIdea::where('content_channel_id', $channel->id)->firstOrFail();
        $this->assertGreaterThan(0, $idea->cross_source_score);
        $this->assertStringContainsString('3 sumber', $idea->source_summary);
    }

    public function test_excluded_keywords_remove_matching_results(): void
    {
        $this->registerProviders(new StubResearchProvider('stub_a', [
            ['Promo judi online terbaru', 'https://example.com/bad'],
            ['Docker 27 dirilis dengan fitur baru', 'https://example.com/good'],
        ]));

        $channel = $this->channelWith(['stub_a'], ['excluded_keywords' => ['judi online']]);
        $this->runEngine($channel);

        $titles = ContentIdea::where('content_channel_id', $channel->id)->pluck('title')->implode(' ');
        $this->assertStringNotContainsString('judi', mb_strtolower($titles));
    }

    public function test_a_topic_already_covered_by_an_existing_idea_is_not_proposed_again(): void
    {
        $title = 'Docker 27 dirilis dengan fitur keamanan baru';

        $this->registerProviders(new StubResearchProvider('stub_a', [[$title, 'https://example.com/docker']]));

        $channel = $this->channelWith(['stub_a']);

        ContentIdea::create([
            'content_channel_id' => $channel->id,
            'user_id' => $channel->user_id,
            'research_date' => now()->toDateString(),
            'topic' => $title,
            'title' => $title,
            'status' => ContentIdea::STATUS_IDEA,
        ]);

        $run = $this->runEngine($channel);

        $this->assertSame(1, $run->duplicates_skipped);
        $this->assertSame(0, $run->ideas_generated);
        // The pre-existing idea is still the only one.
        $this->assertSame(1, ContentIdea::where('content_channel_id', $channel->id)->count());
    }

    public function test_the_same_topic_is_still_offered_to_a_different_channel(): void
    {
        $title = 'Docker 27 dirilis dengan fitur keamanan baru';

        $this->registerProviders(new StubResearchProvider('stub_a', [[$title, 'https://example.com/docker']]));

        $first = $this->channelWith(['stub_a']);
        $this->runEngine($first);
        $this->assertSame(1, ContentIdea::where('content_channel_id', $first->id)->count());

        // A second channel, same research: duplicate detection is per channel, so this
        // must still produce an idea (spec section 20).
        $second = ContentChannel::create([
            'user_id' => $first->user_id,
            'platform_id' => $first->platform_id,
            'name' => 'Second Channel',
            'language' => 'id',
            'niche' => 'Teknologi',
            'keywords' => ['docker'],
            'ideas_per_run' => 3,
        ]);
        $second->researchSources()->attach(ResearchSource::firstWhere('key', 'stub_a')->id, [
            'enabled' => true, 'weight' => 1.0, 'priority' => 10, 'configuration' => [],
        ]);

        $this->runEngine($second->fresh());

        $this->assertSame(1, ContentIdea::where('content_channel_id', $second->id)->count());
    }

    public function test_scores_are_normalized_and_priority_reflects_the_configured_weights(): void
    {
        $this->registerProviders(new StubResearchProvider('stub_a', [
            ['Docker linux container terbaru dirilis', 'https://example.com/x'],
        ]));

        $channel = $this->channelWith(['stub_a']);
        $this->runEngine($channel);

        $idea = ContentIdea::where('content_channel_id', $channel->id)->firstOrFail();

        foreach (['trend_score', 'relevance_score', 'originality_score', 'freshness_score', 'engagement_score', 'cross_source_score', 'priority_score'] as $field) {
            $this->assertGreaterThanOrEqual(0, $idea->$field, $field);
            $this->assertLessThanOrEqual(100, $idea->$field, $field);
        }

        // Keywords are 'docker'/'linux' and the title contains both, so relevance must
        // register rather than sit at zero.
        $this->assertGreaterThan(0, $idea->relevance_score);
        $this->assertGreaterThan(0, $idea->priority_score);
    }

    public function test_a_channel_whose_schedule_time_has_passed_is_due(): void
    {
        $channel = $this->channelWith([], [
            'scheduler_enabled' => true,
            'research_frequency' => 'daily',
            'research_times' => ['06:00'],
        ]);

        $this->assertFalse($channel->isDue(), 'A channel with no next_research_at yet must not fire.');

        $channel->update(['next_research_at' => CarbonImmutable::now()->subMinute()]);
        $this->assertTrue($channel->fresh()->isDue());

        $channel->update(['next_research_at' => CarbonImmutable::now()->addHour()]);
        $this->assertFalse($channel->fresh()->isDue());

        // A disabled scheduler is never due, even with a stale timestamp.
        $channel->update(['scheduler_enabled' => false, 'next_research_at' => CarbonImmutable::now()->subDay()]);
        $this->assertFalse($channel->fresh()->isDue());
    }

    public function test_every_n_hours_expands_into_concrete_slots(): void
    {
        $channel = $this->channelWith([], [
            'research_frequency' => 'every_n_hours',
            'interval_hours' => 6,
        ]);

        $this->assertSame(['00:00', '06:00', '12:00', '18:00'], $channel->scheduleTimes());
    }

    public function test_the_next_run_is_computed_in_the_channels_own_timezone(): void
    {
        $channel = $this->channelWith([], [
            'timezone' => 'Asia/Jakarta',
            'research_frequency' => 'daily',
            'research_times' => ['06:00'],
        ]);

        // 05:00 Jakarta on the dot — the 06:00 slot is still ahead, same day.
        $next = $channel->nextRunAfter(CarbonImmutable::parse('2026-09-06 05:00:00', 'Asia/Jakarta'));

        $this->assertSame('2026-09-06 06:00:00', $next->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'));

        // Past it, the next slot rolls to tomorrow rather than "now + 24h".
        $next = $channel->nextRunAfter(CarbonImmutable::parse('2026-09-06 07:00:00', 'Asia/Jakarta'));
        $this->assertSame('2026-09-07 06:00:00', $next->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'));
    }
}

/**
 * A ResearchProvider that returns fixed items, registered through the real manager.
 */
class StubResearchProvider implements ResearchProvider
{
    /**
     * @param  array<int, array{0: string, 1: string}>  $items  [title, url] pairs
     */
    public function __construct(
        private readonly string $key,
        private readonly array $items,
        private readonly bool $throws = false,
        private readonly bool $requiresCredentials = false,
        private readonly bool $configured = true,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return ucfirst($this->key);
    }

    public function type(): string
    {
        return 'general';
    }

    public function requiresCredentials(): bool
    {
        return $this->requiresCredentials;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function search(ResearchQuery $query): array
    {
        if ($this->throws) {
            throw new RuntimeException('stub provider is down');
        }

        return array_map(fn (array $item) => new ResearchItem(
            sourceKey: $this->key,
            title: $item[0],
            url: $item[1],
            summary: null,
            publishedAt: CarbonImmutable::now()->subHours(2),
            engagement: ['score' => 120, 'comments' => 30],
        ), $this->items);
    }

    public function trending(ResearchQuery $query): array
    {
        return [];
    }

    public function healthCheck(): ProviderHealth
    {
        return new ProviderHealth($this->key, ! $this->throws, 'stub');
    }

    public function configSchema(): array
    {
        return [];
    }
}
