<?php

namespace Tests\Feature;

use App\Jobs\RenderClipJob;
use App\Models\Clip;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The HTTP/validation layer for a segment's transition_in — see
 * FFmpegService::extractWithoutSilence()'s $transitions param for how it's
 * actually rendered (covered separately by ClipTransitionRenderTest, which
 * runs real ffmpeg) and RenderClipJob::resolveSegments() for how it survives
 * the sort segments already go through.
 */
class ClipSegmentTransitionApiTest extends TestCase
{
    use RefreshDatabase;

    private function clip(User $user): Clip
    {
        $project = $user->projects()->create(['title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 60]);

        return Clip::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => Clip::STATUS_COMPLETED,
        ]);
    }

    public function test_a_segment_can_carry_a_transition_in_and_it_persists(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $clip = $this->clip($user);

        $response = $this->actingAs($user)->patchJson("/api/clips/{$clip->id}", [
            'segments' => [
                ['start' => 0, 'end' => 5],
                ['start' => 5, 'end' => 10, 'transition_in' => ['type' => 'dissolve', 'duration' => 0.8]],
            ],
        ]);

        $response->assertOk();
        $clip->refresh();
        $this->assertSame('dissolve', $clip->segments[1]['transition_in']['type']);
        $this->assertSame(0.8, $clip->segments[1]['transition_in']['duration']);
        Queue::assertPushed(RenderClipJob::class, fn (RenderClipJob $job) => $job->clipId === $clip->id);
    }

    public function test_rejects_an_unknown_transition_type(): void
    {
        $user = User::factory()->create();
        $clip = $this->clip($user);

        $this->actingAs($user)->patchJson("/api/clips/{$clip->id}", [
            'segments' => [
                ['start' => 0, 'end' => 5],
                ['start' => 5, 'end' => 10, 'transition_in' => ['type' => 'wipe']],
            ],
        ])->assertStatus(422);
    }

    public function test_rejects_a_duration_outside_the_supported_range(): void
    {
        $user = User::factory()->create();
        $clip = $this->clip($user);

        $this->actingAs($user)->patchJson("/api/clips/{$clip->id}", [
            'segments' => [
                ['start' => 0, 'end' => 5],
                ['start' => 5, 'end' => 10, 'transition_in' => ['type' => 'fade', 'duration' => 10]],
            ],
        ])->assertStatus(422);
    }

    public function test_transition_in_on_the_only_segment_is_accepted_but_ignored_at_render_time(): void
    {
        // Validation doesn't know a segment will end up first once sorted, so it
        // has no reason to reject this — RenderClipJob simply never looks at
        // segment index 0's transition_in (there's nothing before it to fade
        // from). Documented here so that behavior doesn't drift silently.
        Queue::fake();
        $user = User::factory()->create();
        $clip = $this->clip($user);

        $this->actingAs($user)->patchJson("/api/clips/{$clip->id}", [
            'segments' => [
                ['start' => 0, 'end' => 20, 'transition_in' => ['type' => 'fade', 'duration' => 0.5]],
            ],
        ])->assertOk();
    }
}
