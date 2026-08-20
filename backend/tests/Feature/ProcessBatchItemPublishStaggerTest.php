<?php

namespace Tests\Feature;

use App\Jobs\ProcessBatchItemJob;
use App\Jobs\PublishClipJob;
use App\Models\Clip;
use App\Models\ClipCandidate;
use App\Models\Project;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\VideoBatchItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessBatchItemPublishStaggerTest extends TestCase
{
    use RefreshDatabase;

    private function readyProjectWithClips(User $user, int $clipCount): array
    {
        $project = $user->projects()->create(['title' => 'Stagger test', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 300]);
        ClipCandidate::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'overall_score' => 80, 'engagement_score' => 80, 'hook_score' => 80,
            'story_score' => 80, 'emotional_score' => 80, 'information_score' => 80, 'viral_potential' => 80,
        ]);

        $clips = collect();
        for ($i = 0; $i < $clipCount; $i++) {
            $clips->push(Clip::create([
                'project_id' => $project->id, 'video_id' => $video->id,
                'start_time' => $i * 20, 'end_time' => $i * 20 + 20, 'duration' => 20,
                'aspect_ratio' => '9:16', 'subtitle_language' => 'en',
                'title' => "Clip {$i}", 'status' => Clip::STATUS_COMPLETED, 'output_path' => "clips/{$i}.mp4",
            ]));
        }

        return [$project, $clips];
    }

    public function test_publishing_multiple_clips_is_staggered_30_to_60_minutes_apart(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$project, $clips] = $this->readyProjectWithClips($user, 3);
        $account = $user->socialAccounts()->create([
            'platform' => 'tiktok', 'account_name' => 'test', 'status' => SocialAccount::STATUS_CONNECTED,
            'auto_publish_enabled' => true,
        ]);
        $batch = $user->videoBatches()->create(['status' => 'running', 'settings' => []]);
        $item = $batch->items()->create(['position' => 0, 'source_url' => 'https://x.test/a', 'status' => VideoBatchItem::STATUS_PENDING, 'project_id' => $project->id]);

        app()->call([new ProcessBatchItemJob($item->id), 'handle']);

        Queue::assertPushed(PublishClipJob::class, 3);

        $posts = SocialPost::where('social_account_id', $account->id)->orderBy('id')->get();
        $this->assertCount(3, $posts);
        $this->assertTrue($posts->every(fn (SocialPost $p) => $p->status === SocialPost::STATUS_SCHEDULED));

        // First clip publishes ~immediately.
        $this->assertTrue($posts[0]->scheduled_at->diffInSeconds(now()) < 10);

        // Each subsequent clip is scheduled 30-60 minutes after the previous one.
        $gap1 = $posts[0]->scheduled_at->diffInSeconds($posts[1]->scheduled_at);
        $gap2 = $posts[1]->scheduled_at->diffInSeconds($posts[2]->scheduled_at);
        $this->assertGreaterThanOrEqual(1800, $gap1);
        $this->assertLessThanOrEqual(3600, $gap1);
        $this->assertGreaterThanOrEqual(1800, $gap2);
        $this->assertLessThanOrEqual(3600, $gap2);

        $this->assertSame(VideoBatchItem::STATUS_COMPLETED, $item->fresh()->status);
        $this->assertStringContainsString('staggered', $item->fresh()->message);
    }

    public function test_retrying_an_item_does_not_double_schedule_already_scheduled_posts(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$project, $clips] = $this->readyProjectWithClips($user, 1);
        $user->socialAccounts()->create([
            'platform' => 'tiktok', 'account_name' => 'test', 'status' => SocialAccount::STATUS_CONNECTED,
            'auto_publish_enabled' => true,
        ]);
        $batch = $user->videoBatches()->create(['status' => 'running', 'settings' => []]);
        $item = $batch->items()->create(['position' => 0, 'source_url' => 'https://x.test/a', 'status' => VideoBatchItem::STATUS_PENDING, 'project_id' => $project->id]);

        app()->call([new ProcessBatchItemJob($item->id), 'handle']);
        app()->call([new ProcessBatchItemJob($item->id), 'handle']);

        Queue::assertPushed(PublishClipJob::class, 1);
        $this->assertSame(1, SocialPost::count());
    }
}
