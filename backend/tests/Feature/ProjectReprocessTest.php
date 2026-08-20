<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVideoJob;
use App\Jobs\ImportVideoJob;
use App\Jobs\RenderClipJob;
use App\Models\Clip;
use App\Models\ClipCandidate;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProjectReprocessTest extends TestCase
{
    use RefreshDatabase;

    private function failedProject(User $user, array $attrs = []): Project
    {
        return $user->projects()->create(array_merge([
            'title' => 'Test project',
            'status' => Project::STATUS_FAILED,
            'failure_reason' => 'Something broke.',
            'last_edited_at' => now(),
        ], $attrs));
    }

    public function test_rejects_a_project_that_is_not_failed(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['title' => 'ok', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);

        $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/reprocess")
            ->assertUnprocessable();
    }

    public function test_resumes_from_import_when_the_video_never_finished_downloading(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $project = $this->failedProject($user);
        $video = $project->videos()->create([
            'source_type' => 'youtube',
            'source_url' => 'https://youtube.com/watch?v=x',
            'status' => 'failed',
            'failure_reason' => 'HTTP 403',
        ]);

        $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/reprocess")
            ->assertOk();

        Bus::assertChained([ImportVideoJob::class, AnalyzeVideoJob::class]);
        $this->assertSame('pending', $video->fresh()->status);
        $this->assertSame(Project::STATUS_UPLOADING, $project->fresh()->status);
    }

    public function test_resumes_from_analyze_when_the_video_is_ready_but_has_no_candidates(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $this->failedProject($user);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready']);

        $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/reprocess")
            ->assertOk();

        Queue::assertPushed(AnalyzeVideoJob::class, fn ($job) => $job->projectId === $project->id && $job->videoId === $video->id);
        $this->assertSame(Project::STATUS_PROCESSING, $project->fresh()->status);
    }

    public function test_resumes_from_render_by_retrying_only_failed_clips(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $this->failedProject($user);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready']);
        ClipCandidate::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'overall_score' => 80, 'engagement_score' => 80, 'hook_score' => 80,
            'story_score' => 80, 'emotional_score' => 80, 'information_score' => 80, 'viral_potential' => 80,
        ]);
        $okClip = Clip::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en', 'status' => Clip::STATUS_COMPLETED,
        ]);
        $brokenClip = Clip::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 20, 'end_time' => 40, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en', 'status' => Clip::STATUS_FAILED,
            'failure_reason' => 'ffmpeg crashed',
        ]);

        $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/reprocess")
            ->assertOk();

        Queue::assertPushed(RenderClipJob::class, 1);
        Queue::assertPushed(RenderClipJob::class, fn ($job) => $job->clipId === $brokenClip->id);
        $this->assertSame(Clip::STATUS_QUEUED, $brokenClip->fresh()->status);
        $this->assertSame(Clip::STATUS_COMPLETED, $okClip->fresh()->status);
    }

    public function test_users_cannot_reprocess_each_others_projects(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = $this->failedProject($owner);

        $this->actingAs($stranger)
            ->postJson("/api/projects/{$project->id}/reprocess")
            ->assertNotFound();
    }
}
