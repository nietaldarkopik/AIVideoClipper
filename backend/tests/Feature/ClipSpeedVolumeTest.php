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
 * Covers the Clip Editor's new Speed/Volume controls at the HTTP/validation
 * layer — see FFmpegService::renderClip()/renderReactionClip() for the actual
 * setpts/atempo/volume filter application, verified separately via a real
 * ffmpeg run (not practical to assert on here without a real video fixture).
 */
class ClipSpeedVolumeTest extends TestCase
{
    use RefreshDatabase;

    private function clip(User $user, array $overrides = []): Clip
    {
        $project = $user->projects()->create(['title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 20]);

        return Clip::create(array_merge([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => Clip::STATUS_COMPLETED,
        ], $overrides));
    }

    public function test_defaults_to_1x_speed_and_volume(): void
    {
        // ->fresh(): Clip::create() only knows the attributes it was explicitly
        // given — speed/volume aren't among them here, so the DB-level default
        // (set in the migration) only shows up in-memory after a re-fetch.
        $clip = $this->clip(User::factory()->create())->fresh();

        $this->assertSame(1.0, $clip->speed);
        $this->assertSame(1.0, $clip->volume);
    }

    public function test_update_accepts_a_custom_speed_and_volume_and_triggers_a_rerender(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $clip = $this->clip($user);

        $response = $this->actingAs($user)->patchJson("/api/clips/{$clip->id}", [
            'speed' => 1.5,
            'volume' => 0.7,
        ]);

        $response->assertOk();
        $clip->refresh();
        $this->assertSame(1.5, $clip->speed);
        $this->assertSame(0.7, $clip->volume);
        $this->assertSame(Clip::STATUS_QUEUED, $clip->status);
        Queue::assertPushed(RenderClipJob::class, fn (RenderClipJob $job) => $job->clipId === $clip->id);
    }

    public function test_rejects_a_speed_outside_the_supported_range(): void
    {
        $user = User::factory()->create();
        $clip = $this->clip($user);

        $this->actingAs($user)->patchJson("/api/clips/{$clip->id}", ['speed' => 3.0])->assertStatus(422);
        $this->actingAs($user)->patchJson("/api/clips/{$clip->id}", ['speed' => 0.1])->assertStatus(422);
    }

    public function test_rejects_a_negative_volume(): void
    {
        $user = User::factory()->create();
        $clip = $this->clip($user);

        $this->actingAs($user)->patchJson("/api/clips/{$clip->id}", ['volume' => -0.5])->assertStatus(422);
    }
}
