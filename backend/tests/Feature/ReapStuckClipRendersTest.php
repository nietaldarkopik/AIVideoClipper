<?php

namespace Tests\Feature;

use App\Jobs\RenderClipJob;
use App\Models\Clip;
use App\Models\ProcessingJob;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * RenderClipJob creates its own ProcessingJob row INSIDE handle() rather than
 * before dispatch — so a dispatch the queue driver drops before it ever runs
 * (this app's real trigger: a Redis restart with AOF off wipes anything not yet
 * flushed to an RDB snapshot) leaves no running row at all for jobs:reap-stalled
 * to find. This command is what notices instead: a clip stuck at queued/rendering
 * with no live ProcessingJob tracking an attempt.
 */
class ReapStuckClipRendersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * $updatedAt is applied via forceFill()+save() AFTER creation, not through
     * the create() array — 'updated_at' isn't mass-assignable, and even if it
     * were, Eloquent's own updateTimestamps() overwrites it to now() on insert
     * unless the attribute is already dirty going into save(), which forceFill()
     * guarantees and a plain create() array does not.
     */
    private function clip(User $user, array $attrs = [], ?\Illuminate\Support\Carbon $updatedAt = null): Clip
    {
        $project = $user->projects()->create(['title' => 'P', 'status' => Project::STATUS_RENDERING, 'last_edited_at' => now()]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 20]);

        $clip = Clip::create(array_merge([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => Clip::STATUS_QUEUED, 'progress' => 0,
        ], $attrs));

        if ($updatedAt) {
            $clip->forceFill(['updated_at' => $updatedAt])->save();
        }

        return $clip;
    }

    public function test_a_clip_stuck_with_no_tracking_job_is_re_dispatched(): void
    {
        Bus::fake();
        $clip = $this->clip(User::factory()->create(), [], now()->subMinutes(30));

        $this->artisan('clips:reap-stuck-renders')->assertSuccessful();

        Bus::assertDispatched(RenderClipJob::class, fn (RenderClipJob $job) => $job->clipId === $clip->id);
        $this->assertSame(Clip::STATUS_QUEUED, $clip->fresh()->status);
        $this->assertSame(0, $clip->fresh()->progress);
    }

    public function test_a_reaped_clip_that_was_already_queued_still_gets_a_fresh_timestamp(): void
    {
        // The dangerous case: a clip that was ALREADY status=queued/progress=0 —
        // the common shape, since that's exactly where a lost dispatch leaves it —
        // makes the status/progress update() above a no-op (nothing is dirty), so
        // without an explicit touch() updated_at would never move. The clip would
        // still read as stale on the very next run, before its redispatched job
        // even reaches the front of a backlogged queue, and get dispatched AGAIN
        // — piling up duplicate RenderClipJob attempts for the same clip.
        Bus::fake();
        $clip = $this->clip(User::factory()->create(), [], now()->subMinutes(30));

        $this->artisan('clips:reap-stuck-renders')->assertSuccessful();

        $this->assertTrue($clip->fresh()->updated_at->isAfter(now()->subMinute()));
    }

    public function test_a_clip_with_a_genuinely_running_render_is_left_alone(): void
    {
        Bus::fake();
        $clip = $this->clip(
            User::factory()->create(),
            ['status' => Clip::STATUS_RENDERING, 'progress' => 55],
            now()->subMinutes(30),
        );
        ProcessingJob::create([
            'project_id' => $clip->project_id, 'video_id' => $clip->video_id, 'clip_id' => $clip->id,
            'type' => 'render_clip', 'status' => ProcessingJob::STATUS_RUNNING, 'started_at' => now(),
        ]);

        $this->artisan('clips:reap-stuck-renders')->assertSuccessful();

        // A multi-minute render legitimately sits at "rendering" for a long time
        // with its ProcessingJob actively updating it — redispatching this one
        // would race the real attempt and double-render the same clip.
        Bus::assertNotDispatched(RenderClipJob::class);
        $this->assertSame(55, $clip->fresh()->progress);
    }

    public function test_a_recently_queued_clip_is_not_touched_yet(): void
    {
        Bus::fake();
        $clip = $this->clip(User::factory()->create(), [], now()->subMinutes(2));

        $this->artisan('clips:reap-stuck-renders')->assertSuccessful();

        // Inside the grace window: this is what a render looks like in the first
        // moments after a normal dispatch, before its ProcessingJob row even
        // exists yet — redispatching here would double-render every clip.
        Bus::assertNotDispatched(RenderClipJob::class);
    }

    public function test_a_completed_or_failed_clip_is_never_touched(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $completed = $this->clip($user, ['status' => Clip::STATUS_COMPLETED], now()->subHours(2));
        $failed = $this->clip($user, ['status' => Clip::STATUS_FAILED], now()->subHours(2));

        $this->artisan('clips:reap-stuck-renders')->assertSuccessful();

        Bus::assertNotDispatched(RenderClipJob::class);
    }

    public function test_the_stall_threshold_is_configurable_via_the_option(): void
    {
        Bus::fake();
        $clip = $this->clip(User::factory()->create(), [], now()->subMinutes(5));

        $this->artisan('clips:reap-stuck-renders', ['--minutes' => 3])->assertSuccessful();

        Bus::assertDispatched(RenderClipJob::class);
    }
}
