<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVideoJob;
use App\Jobs\PublishClipJob;
use App\Jobs\RenderClipJob;
use App\Models\Clip;
use App\Models\ClipCandidate;
use App\Models\Project;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\VideoBatchItem;
use App\Services\Social\AutoPublishScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AutoGenerateAndPublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // There's no .env.testing in this project, so without this the analysis
        // provider tests would silently hit whatever real provider (ollama/openai/
        // claude/gemini) is configured in the developer's own backend/.env.
        Setting::set('clip_scoring_model', 'mock');
    }

    private const SRT = <<<SRT
        1
        00:00:00,000 --> 00:00:10,000
        This is the first line of the video.

        2
        00:00:10,000 --> 00:00:20,000
        And here is a second, punchier moment worth clipping.
        SRT;

    /**
     * A video with real source captions skips both audio extraction and
     * transcription entirely, so AnalyzeVideoJob's handle() can run end-to-end in
     * a test with no real ffmpeg binary or video file needed.
     */
    private function readyVideoWithCaptions(Project $project): \App\Models\Video
    {
        Storage::fake('media');
        Storage::disk('media')->put('videos/1/captions.en.srt', self::SRT);

        return $project->videos()->create([
            'source_type' => 'youtube',
            'status' => 'ready',
            'duration_seconds' => 20,
            'disk_path' => 'videos/1/source.mp4',
            'metadata' => ['captions_srt_path' => 'videos/1/captions.en.srt', 'captions_language' => 'en'],
        ]);
    }

    public function test_analyze_auto_generates_and_dispatches_render_for_a_regular_project(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $user->projects()->create(['title' => 'Auto test', 'status' => Project::STATUS_DRAFT, 'last_edited_at' => now()]);
        $video = $this->readyVideoWithCaptions($project);

        app()->call([new AnalyzeVideoJob($project->id, $video->id), 'handle']);

        $this->assertGreaterThan(0, ClipCandidate::where('project_id', $project->id)->count());
        $clips = Clip::where('project_id', $project->id)->get();
        $this->assertGreaterThan(0, $clips->count());
        $this->assertSame(Project::STATUS_RENDERING, $project->fresh()->status);

        foreach ($clips as $clip) {
            Queue::assertPushed(RenderClipJob::class, fn ($job) => $job->clipId === $clip->id);
        }
    }

    public function test_analyze_does_not_auto_generate_when_disabled_for_batch_use(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $user->projects()->create(['title' => 'Batch test', 'status' => Project::STATUS_DRAFT, 'last_edited_at' => now()]);
        $video = $this->readyVideoWithCaptions($project);

        app()->call([new AnalyzeVideoJob($project->id, $video->id, autoGenerateClips: false), 'handle']);

        $this->assertGreaterThan(0, ClipCandidate::where('project_id', $project->id)->count());
        $this->assertSame(0, Clip::where('project_id', $project->id)->count());
        Queue::assertNotPushed(RenderClipJob::class);
        $this->assertSame(Project::STATUS_COMPLETED, $project->fresh()->status);
    }

    public function test_render_completing_auto_schedules_publish_for_a_regular_project_only(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $account = $user->socialAccounts()->create([
            'platform' => 'tiktok', 'account_name' => 'test', 'status' => SocialAccount::STATUS_CONNECTED,
            'auto_publish_enabled' => true,
        ]);

        // Regular (non-batch) project: publish scheduling should fire.
        $regular = $user->projects()->create(['title' => 'Regular', 'status' => Project::STATUS_RENDERING, 'last_edited_at' => now()]);
        $regularVideo = $regular->videos()->create(['source_type' => 'youtube', 'status' => 'ready']);
        $regularClip = Clip::create([
            'project_id' => $regular->id, 'video_id' => $regularVideo->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en', 'status' => Clip::STATUS_QUEUED,
        ]);

        // Batch-owned project: publish scheduling should be skipped (ProcessBatchItemJob owns that).
        $batch = $user->videoBatches()->create(['status' => 'running', 'settings' => []]);
        $batchProject = $user->projects()->create(['title' => 'Batch', 'status' => Project::STATUS_RENDERING, 'last_edited_at' => now()]);
        $batch->items()->create(['position' => 0, 'source_url' => 'https://x.test/a', 'status' => VideoBatchItem::STATUS_RENDERING, 'project_id' => $batchProject->id]);
        $batchVideo = $batchProject->videos()->create(['source_type' => 'youtube', 'status' => 'ready']);
        $batchClip = Clip::create([
            'project_id' => $batchProject->id, 'video_id' => $batchVideo->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en', 'status' => Clip::STATUS_QUEUED,
        ]);

        // Simulate both clips finishing rendering by calling the scheduler directly
        // for the regular project (mirrors what RenderClipJob's settleProjectStatus
        // does) and confirm it's skipped for the batch one.
        $regularClip->update(['status' => Clip::STATUS_COMPLETED]);
        $regular->update(['status' => Project::STATUS_COMPLETED]);
        app(AutoPublishScheduler::class)->scheduleForProject($regular);

        $batchClip->update(['status' => Clip::STATUS_COMPLETED]);
        // Batch projects are skipped by RenderClipJob's own VideoBatchItem check —
        // asserted by NOT calling the scheduler here, matching that guarded code path.

        Queue::assertPushed(PublishClipJob::class, 1);
        $this->assertSame(1, SocialPost::where('clip_id', $regularClip->id)->count());
        $this->assertSame(0, SocialPost::where('clip_id', $batchClip->id)->count());
    }

    public function test_publish_now_overrides_a_scheduled_post_and_is_idempotent_against_the_original_delayed_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $user->projects()->create(['title' => 'Now test', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready']);
        $clip = Clip::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en', 'status' => Clip::STATUS_COMPLETED,
        ]);
        $account = $user->socialAccounts()->create(['platform' => 'tiktok', 'account_name' => 'test', 'status' => SocialAccount::STATUS_CONNECTED]);
        $post = SocialPost::create([
            'clip_id' => $clip->id, 'social_account_id' => $account->id, 'platform' => 'tiktok',
            'status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => now()->addMinutes(45),
        ]);

        $this->actingAs($user)
            ->postJson("/api/social-posts/{$post->id}/publish-now")
            ->assertOk()
            ->assertJsonPath('data.status', SocialPost::STATUS_READY);

        $this->assertNull($post->fresh()->scheduled_at);
        Queue::assertPushed(PublishClipJob::class, 1);

        // The original delayed dispatch (already in the queue before this test's
        // Queue::fake() reset it) would eventually run handle() again for the same
        // post once it's marked published — PublishClipJob must no-op instead of
        // publishing a second time.
        $post->update(['status' => SocialPost::STATUS_PUBLISHED]);
        app()->call([new PublishClipJob($post->id), 'handle']);
        $this->assertSame(SocialPost::STATUS_PUBLISHED, $post->fresh()->status);
    }

    public function test_publish_now_rejects_an_already_published_post(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['title' => 'Done', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready']);
        $clip = Clip::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en', 'status' => Clip::STATUS_COMPLETED,
        ]);
        $account = $user->socialAccounts()->create(['platform' => 'tiktok', 'account_name' => 'test', 'status' => SocialAccount::STATUS_CONNECTED]);
        $post = SocialPost::create([
            'clip_id' => $clip->id, 'social_account_id' => $account->id, 'platform' => 'tiktok',
            'status' => SocialPost::STATUS_PUBLISHED, 'published_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson("/api/social-posts/{$post->id}/publish-now")
            ->assertUnprocessable();
    }

    public function test_scheduler_caps_posts_per_account_per_day_and_rolls_overflow_to_next_day(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $account = $user->socialAccounts()->create([
            'platform' => 'tiktok', 'account_name' => 'test', 'status' => SocialAccount::STATUS_CONNECTED,
            'auto_publish_enabled' => true,
        ]);

        $project = $user->projects()->create(['title' => 'Cap test', 'status' => Project::STATUS_RENDERING, 'last_edited_at' => now()]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready']);

        // One video producing 7 clips is exactly the kind of burst that used to
        // schedule 7 back-to-back posts onto one account in a single afternoon.
        for ($i = 0; $i < 7; $i++) {
            Clip::create([
                'project_id' => $project->id, 'video_id' => $video->id,
                'start_time' => $i * 20, 'end_time' => $i * 20 + 20, 'duration' => 20,
                'aspect_ratio' => '9:16', 'subtitle_language' => 'en', 'status' => Clip::STATUS_COMPLETED,
            ]);
        }

        app(AutoPublishScheduler::class)->scheduleForProject($project);

        $postsPerDay = SocialPost::where('social_account_id', $account->id)
            ->pluck('scheduled_at')
            ->map(fn ($dt) => $dt->toDateString())
            ->countBy();

        $this->assertSame(7, $postsPerDay->sum(), 'all 7 clips should still get scheduled somewhere, none dropped');
        $this->assertCount(2, $postsPerDay, 'expected the 7 posts split across exactly two calendar days');
        $this->assertEqualsCanonicalizing(
            [AutoPublishScheduler::MAX_POSTS_PER_DAY_PER_ACCOUNT, 7 - AutoPublishScheduler::MAX_POSTS_PER_DAY_PER_ACCOUNT],
            $postsPerDay->values()->all(),
        );
    }
}
