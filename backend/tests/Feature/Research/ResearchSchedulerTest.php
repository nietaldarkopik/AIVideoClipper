<?php

namespace Tests\Feature\Research;

use App\Jobs\ResearchChannelJob;
use App\Models\ContentChannel;
use App\Models\Platform;
use App\Models\ResearchRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResearchSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platform;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = Platform::create(['key' => 'youtube', 'name' => 'YouTube', 'type' => 'video']);
        $this->user = User::factory()->create();
    }

    private function channel(string $name, array $overrides = []): ContentChannel
    {
        return ContentChannel::create(array_merge([
            'user_id' => $this->user->id,
            'platform_id' => $this->platform->id,
            'name' => $name,
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
            'scheduler_enabled' => true,
            'research_frequency' => 'daily',
            'research_times' => ['06:00'],
            'next_research_at' => CarbonImmutable::now()->subMinute(),
        ], $overrides));
    }

    public function test_only_due_and_enabled_channels_are_dispatched(): void
    {
        Queue::fake();

        $due = $this->channel('Due');
        $notYet = $this->channel('Not Yet', ['next_research_at' => CarbonImmutable::now()->addHours(3)]);
        $disabled = $this->channel('Scheduler Off', ['scheduler_enabled' => false]);
        $inactive = $this->channel('Inactive', ['is_active' => false]);

        $this->artisan('research:daily')->assertSuccessful();

        Queue::assertPushed(ResearchChannelJob::class, 1);

        $this->assertSame(1, ResearchRun::count());
        $this->assertSame($due->id, ResearchRun::first()->content_channel_id);

        foreach ([$notYet, $disabled, $inactive] as $channel) {
            $this->assertSame(0, ResearchRun::where('content_channel_id', $channel->id)->count());
        }
    }

    public function test_each_due_channel_gets_its_own_independent_job(): void
    {
        Queue::fake();

        $this->channel('One');
        $this->channel('Two');
        $this->channel('Three');

        $this->artisan('research:daily')->assertSuccessful();

        // One job per channel is what makes a single channel's failure unable to stop
        // the others — the isolation is structural, not a try/catch.
        Queue::assertPushed(ResearchChannelJob::class, 3);
        $this->assertSame(3, ResearchRun::count());
    }

    public function test_one_channel_failing_to_dispatch_does_not_stop_the_rest(): void
    {
        Queue::fake();

        $blocked = $this->channel('Already Running');
        $healthy = $this->channel('Healthy');

        // A run already in flight makes the launcher refuse this channel.
        ResearchRun::create([
            'content_channel_id' => $blocked->id,
            'user_id' => $this->user->id,
            'trigger' => 'scheduled',
            'status' => ResearchRun::STATUS_RUNNING,
        ]);

        $this->artisan('research:daily')->assertSuccessful();

        Queue::assertPushed(ResearchChannelJob::class, 1);
        $this->assertSame(1, ResearchRun::where('content_channel_id', $healthy->id)->count());
    }

    public function test_a_newly_enabled_channel_is_stamped_rather_than_fired_immediately(): void
    {
        Queue::fake();

        $channel = $this->channel('Just Enabled', ['next_research_at' => null]);

        $this->artisan('research:daily')->assertSuccessful();

        // Enabling a channel at an arbitrary hour must not trigger an unscheduled run;
        // it joins the schedule from its next real slot.
        Queue::assertNothingPushed();
        $this->assertNotNull($channel->fresh()->next_research_at);
    }

    public function test_dispatching_advances_the_next_run_so_the_channel_is_not_picked_up_twice(): void
    {
        Queue::fake();

        $channel = $this->channel('Advance');

        $this->artisan('research:daily')->assertSuccessful();

        $this->assertTrue($channel->fresh()->next_research_at->isFuture());

        // A second tick before that time must not dispatch again.
        $this->artisan('research:daily')->assertSuccessful();
        Queue::assertPushed(ResearchChannelJob::class, 1);
    }

    public function test_the_channel_option_runs_a_channel_regardless_of_its_schedule(): void
    {
        Queue::fake();

        $channel = $this->channel('Off Schedule', [
            'scheduler_enabled' => false,
            'next_research_at' => null,
        ]);

        $this->artisan('research:daily', ['--channel' => $channel->id])->assertSuccessful();

        Queue::assertPushed(ResearchChannelJob::class, 1);
        $this->assertSame('manual', ResearchRun::first()->trigger);
    }

    public function test_a_manual_run_is_queued_not_executed_in_the_request(): void
    {
        Queue::fake();

        $channel = $this->channel('Manual');

        $this->actingAs($this->user)
            ->postJson("/api/content-channels/{$channel->id}/research-runs")
            ->assertStatus(202)
            ->assertJsonPath('data.status', ResearchRun::STATUS_RUNNING)
            ->assertJsonPath('data.trigger', 'manual');

        Queue::assertPushed(ResearchChannelJob::class, 1);
    }

    public function test_a_manual_run_is_refused_while_one_is_already_in_flight(): void
    {
        Queue::fake();

        $channel = $this->channel('Busy');

        $this->actingAs($this->user)->postJson("/api/content-channels/{$channel->id}/research-runs")->assertStatus(202);
        $this->actingAs($this->user)->postJson("/api/content-channels/{$channel->id}/research-runs")->assertStatus(422);

        $this->assertSame(1, ResearchRun::count());
    }

    public function test_a_stuck_job_marks_its_run_failed_instead_of_leaving_it_running(): void
    {
        $channel = $this->channel('Stuck');

        $run = ResearchRun::create([
            'content_channel_id' => $channel->id,
            'user_id' => $this->user->id,
            'trigger' => 'scheduled',
            'status' => ResearchRun::STATUS_RUNNING,
        ]);

        (new ResearchChannelJob($run->id))->failed(new \RuntimeException('worker died'));

        $run->refresh();
        $this->assertSame(ResearchRun::STATUS_FAILED, $run->status);
        $this->assertSame('worker died', $run->error_message);
        $this->assertNotNull($run->finished_at);
    }
}
