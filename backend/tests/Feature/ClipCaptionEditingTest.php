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
 * Covers editable captions end to end at the HTTP layer: cues save, come back
 * through the editor's own preview-config endpoint, survive a reload, and can be
 * handed back to the transcript pipeline. The burn-in itself (toAss/toSrt +
 * ffmpeg) is exercised by a real render, not here — see RenderClipJob's
 * $hasEditedCaptions branch for how these cues reach it.
 */
class ClipCaptionEditingTest extends TestCase
{
    use RefreshDatabase;

    private function clip(User $user, array $overrides = []): Clip
    {
        $project = $user->projects()->create(['title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 60]);

        return Clip::create(array_merge([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 5, 'end_time' => 25, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => Clip::STATUS_COMPLETED,
        ], $overrides));
    }

    public function test_caption_cues_default_to_null_so_captions_stay_transcript_driven(): void
    {
        $clip = $this->clip(User::factory()->create())->fresh();

        $this->assertNull($clip->caption_cues);
    }

    public function test_editing_cues_saves_them_and_triggers_a_rerender(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $clip = $this->clip($user);

        $response = $this->actingAs($user)->patchJson("/api/clips/{$clip->id}", [
            'caption_cues' => [
                ['start' => 0, 'end' => 1.5, 'text' => 'Hello there', 'words' => [
                    ['word' => 'Hello', 'start' => 0, 'end' => 0.7],
                    ['word' => 'there', 'start' => 0.7, 'end' => 1.5],
                ]],
                ['start' => 1.5, 'end' => 3.0, 'text' => 'Second line', 'words' => []],
            ],
        ]);

        $response->assertOk();
        $clip->refresh();
        $this->assertCount(2, $clip->caption_cues);
        $this->assertSame('Hello there', $clip->caption_cues[0]['text']);
        // Word-level timing survives the round trip — it's what per-word caption
        // highlighting renders from.
        $this->assertCount(2, $clip->caption_cues[0]['words']);
        $this->assertSame(Clip::STATUS_QUEUED, $clip->status);
        Queue::assertPushed(RenderClipJob::class, fn (RenderClipJob $job) => $job->clipId === $clip->id);
    }

    public function test_preview_config_serves_edited_cues_over_the_last_renders_own(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $clip = $this->clip($user);

        // What the last render produced, which is what the editor showed before
        // anyone touched a caption.
        $clip->subtitle()->create([
            'language' => 'en',
            'segments' => [['start' => 0, 'end' => 2, 'text' => 'Transcript line', 'words' => []]],
        ]);

        $this->actingAs($user)
            ->getJson("/api/clips/{$clip->id}/preview-config")
            ->assertOk()
            ->assertJsonPath('data.subtitle_cues.0.text', 'Transcript line')
            ->assertJsonPath('data.caption_cues_edited', false);

        $this->actingAs($user)->patchJson("/api/clips/{$clip->id}", [
            'caption_cues' => [['start' => 0, 'end' => 2, 'text' => 'Edited line', 'words' => []]],
        ])->assertOk();

        // Reopening the editor must show the edit, not the stale render.
        $this->actingAs($user)
            ->getJson("/api/clips/{$clip->id}/preview-config")
            ->assertOk()
            ->assertJsonPath('data.subtitle_cues.0.text', 'Edited line')
            ->assertJsonPath('data.caption_cues_edited', true);
    }

    public function test_cues_can_be_cleared_to_go_back_to_auto_generated_captions(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $clip = $this->clip($user, ['caption_cues' => [['start' => 0, 'end' => 1, 'text' => 'Edited', 'words' => []]]]);

        $this->actingAs($user)->patchJson("/api/clips/{$clip->id}", ['caption_cues' => null])->assertOk();

        $this->assertNull($clip->fresh()->caption_cues);
    }

    public function test_rejects_a_cue_missing_its_timing(): void
    {
        $user = User::factory()->create();
        $clip = $this->clip($user);

        $this->actingAs($user)
            ->patchJson("/api/clips/{$clip->id}", ['caption_cues' => [['text' => 'No timing']]])
            ->assertStatus(422);
    }
}
