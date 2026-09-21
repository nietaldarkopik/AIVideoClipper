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
 * The HTTP/validation layer for additional_video_clips — see
 * RenderClipJob::renderAdditionalVideoClips() for how each entry is actually
 * rendered (covered separately by RenderAdditionalVideoClipsTest, with real
 * ffmpeg) and FFmpegService::concatSegments() for how the results are joined.
 */
class AdditionalVideoClipsApiTest extends TestCase
{
    use RefreshDatabase;

    private function clipWithVideos(User $user): array
    {
        $project = $user->projects()->create(['title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $mainVideo = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 20]);
        $otherVideo = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 15]);

        $clip = Clip::create([
            'project_id' => $project->id, 'video_id' => $mainVideo->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => Clip::STATUS_COMPLETED,
        ]);

        return [$clip, $otherVideo, $project];
    }

    public function test_an_additional_clip_from_another_video_in_the_project_persists_and_triggers_a_rerender(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$clip, $otherVideo] = $this->clipWithVideos($user);

        $response = $this->actingAs($user)->patchJson("/api/clips/{$clip->id}", [
            'additional_video_clips' => [
                ['video_id' => $otherVideo->id, 'start' => 1, 'end' => 5, 'transition_in' => ['type' => 'fade', 'duration' => 0.5]],
            ],
        ]);

        $response->assertOk();
        $clip->refresh();
        $this->assertCount(1, $clip->additional_video_clips);
        $this->assertSame($otherVideo->id, $clip->additional_video_clips[0]['video_id']);
        $this->assertSame('fade', $clip->additional_video_clips[0]['transition_in']['type']);
        $this->assertSame(Clip::STATUS_QUEUED, $clip->status);
        Queue::assertPushed(RenderClipJob::class, fn (RenderClipJob $job) => $job->clipId === $clip->id);
    }

    public function test_rejects_a_video_from_another_project(): void
    {
        $user = User::factory()->create();
        [$clip] = $this->clipWithVideos($user);

        // A second project's own video — must not be addable from this clip.
        $otherProject = $user->projects()->create(['title' => 'Other', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $foreignVideo = $otherProject->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 10]);

        $this->actingAs($user)->patchJson("/api/clips/{$clip->id}", [
            'additional_video_clips' => [
                ['video_id' => $foreignVideo->id, 'start' => 0, 'end' => 3],
            ],
        ])->assertStatus(422);
    }

    public function test_rejects_a_nonexistent_video_id(): void
    {
        $user = User::factory()->create();
        [$clip] = $this->clipWithVideos($user);

        $this->actingAs($user)->patchJson("/api/clips/{$clip->id}", [
            'additional_video_clips' => [
                ['video_id' => 999999, 'start' => 0, 'end' => 3],
            ],
        ])->assertStatus(422);
    }

    public function test_rejects_an_unknown_transition_type(): void
    {
        $user = User::factory()->create();
        [$clip, $otherVideo] = $this->clipWithVideos($user);

        $this->actingAs($user)->patchJson("/api/clips/{$clip->id}", [
            'additional_video_clips' => [
                ['video_id' => $otherVideo->id, 'start' => 0, 'end' => 3, 'transition_in' => ['type' => 'wipe']],
            ],
        ])->assertStatus(422);
    }

    public function test_requires_start_and_end_on_every_entry(): void
    {
        $user = User::factory()->create();
        [$clip, $otherVideo] = $this->clipWithVideos($user);

        $this->actingAs($user)->patchJson("/api/clips/{$clip->id}", [
            'additional_video_clips' => [
                ['video_id' => $otherVideo->id],
            ],
        ])->assertStatus(422);
    }

    public function test_can_be_cleared_back_to_none(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$clip, $otherVideo] = $this->clipWithVideos($user);
        $clip->update(['additional_video_clips' => [['video_id' => $otherVideo->id, 'start' => 0, 'end' => 3]]]);

        $this->actingAs($user)->patchJson("/api/clips/{$clip->id}", ['additional_video_clips' => null])->assertOk();

        $this->assertNull($clip->fresh()->additional_video_clips);
    }
}
