<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/projects/{project} exposing the FULL video list, not just the
 * latest one — added for the clip editor's "additional video clips" picker
 * (see RenderClipJob::renderAdditionalVideoClips()), which needs to offer
 * every video in the project, not only the one 'video' already surfaces.
 */
class ProjectVideosListTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_exposes_every_video_in_the_project(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 10]);
        $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 20]);

        $response = $this->actingAs($user)->getJson("/api/projects/{$project->id}")->assertOk();

        // The pre-existing singular key stays intact (the LATEST video)...
        $this->assertNotNull($response->json('data.video'));
        // ...alongside the new full list.
        $this->assertCount(2, $response->json('data.videos'));
    }
}
