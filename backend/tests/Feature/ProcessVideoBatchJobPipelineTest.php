<?php

namespace Tests\Feature;

use App\Jobs\ProcessBatchItemJob;
use App\Jobs\ProcessVideoBatchJob;
use App\Models\Clip;
use App\Models\ClipCandidate;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoBatchItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ProcessVideoBatchJob only downloads (see its docblock) — the rest of each
 * item's pipeline is handed off to a separately-dispatched ProcessBatchItemJob so
 * it can run independently while the orchestrator moves on to the next item's
 * download instead of waiting. These tests use items whose projects are already
 * past the import stage (video status 'ready') so the orchestrator's download
 * step is a no-op and no real yt-dlp call happens — the thing under test is the
 * dispatch-then-continue loop itself, not the download.
 */
class ProcessVideoBatchJobPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function alreadyImportedProject(User $user): Project
    {
        $project = $user->projects()->create(['title' => 'x', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 300]);
        ClipCandidate::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'overall_score' => 80, 'engagement_score' => 80, 'hook_score' => 80,
            'story_score' => 80, 'emotional_score' => 80, 'information_score' => 80, 'viral_potential' => 80,
        ]);
        Clip::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en',
            'title' => 'clip', 'status' => Clip::STATUS_COMPLETED, 'output_path' => 'clips/x.mp4',
        ]);

        return $project;
    }

    public function test_dispatches_processing_for_every_item_without_waiting_for_it_to_finish(): void
    {
        config(['services.video_batch.download_delay_seconds' => 0]);
        Queue::fake();

        $user = User::factory()->create();
        $batch = $user->videoBatches()->create(['status' => 'pending', 'settings' => []]);
        $items = collect(range(0, 2))->map(fn ($i) => $batch->items()->create([
            'position' => $i,
            'source_url' => "https://x.test/{$i}",
            'status' => VideoBatchItem::STATUS_PENDING,
            'project_id' => $this->alreadyImportedProject($user)->id,
        ]));

        app()->call([new ProcessVideoBatchJob($batch->id), 'handle']);

        // All three items' processing got dispatched — the orchestrator never
        // blocked on any of them actually completing (Queue::fake() guarantees
        // nothing behind ProcessBatchItemJob::dispatch() ran synchronously).
        Queue::assertPushed(ProcessBatchItemJob::class, 3);
        foreach ($items as $item) {
            Queue::assertPushed(ProcessBatchItemJob::class, fn ($job) => $job->videoBatchItemId === $item->id);
        }

        $this->assertSame('running', $batch->fresh()->status);
    }

    public function test_stops_dispatching_once_cancellation_is_requested(): void
    {
        config(['services.video_batch.download_delay_seconds' => 0]);
        Queue::fake();

        $user = User::factory()->create();
        $batch = $user->videoBatches()->create(['status' => 'running', 'cancel_requested' => true, 'settings' => []]);
        $batch->items()->create([
            'position' => 0, 'source_url' => 'https://x.test/0',
            'status' => VideoBatchItem::STATUS_PENDING, 'project_id' => $this->alreadyImportedProject($user)->id,
        ]);

        app()->call([new ProcessVideoBatchJob($batch->id), 'handle']);

        Queue::assertNotPushed(ProcessBatchItemJob::class);
        $this->assertSame(VideoBatchItem::STATUS_CANCELLED, $batch->fresh()->items->first()->status);
    }
}
