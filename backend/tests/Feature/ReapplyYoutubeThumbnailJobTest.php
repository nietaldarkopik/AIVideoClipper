<?php

namespace Tests\Feature;

use App\Jobs\PublishClipJob;
use App\Jobs\ReapplyYoutubeThumbnailJob;
use App\Models\Clip;
use App\Models\Project;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the real YouTube quirk reported after the thumbnails.set fix: the
 * thumbnail looks correct in Studio right after publish, then reverts to an
 * auto-picked video frame once YouTube's own processing pipeline finishes.
 * ReapplyYoutubeThumbnailJob polls videos.list and re-sets the thumbnail once
 * processing is actually done, since there is no webhook for that transition.
 */
class ReapplyYoutubeThumbnailJobTest extends TestCase
{
    use RefreshDatabase;

    private function coverAndPost(): array
    {
        Storage::fake('media');
        Storage::disk('media')->put('covers/x.jpg', base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/wAALCAABAAEBAREA/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAA/AKgH/9k='
        ));

        $user = User::factory()->create();
        $account = $user->socialAccounts()->create([
            'platform' => 'youtube', 'account_name' => 'Test Channel', 'status' => SocialAccount::STATUS_CONNECTED,
            'access_token' => 'fake-access-token', 'refresh_token' => 'fake-refresh-token', 'token_expires_at' => now()->addHour(),
        ]);
        $project = $user->projects()->create(['title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 20]);
        $clip = Clip::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => Clip::STATUS_COMPLETED, 'output_path' => 'clips/x.mp4',
        ]);
        $post = SocialPost::create([
            'clip_id' => $clip->id, 'social_account_id' => $account->id, 'platform' => 'youtube',
            'status' => SocialPost::STATUS_PUBLISHED, 'scheduled_at' => now(), 'published_at' => now(),
            'external_post_id' => 'VIDEO123',
        ]);

        return [$post, 'covers/x.jpg'];
    }

    public function test_a_successful_youtube_publish_with_a_cover_schedules_a_reapply_check(): void
    {
        Bus::fake();
        Storage::fake('media');
        Storage::disk('media')->put('covers/x.jpg', 'fake-cover-bytes');
        Storage::disk('media')->put('clips/x.mp4', 'fake-video-bytes');

        $user = User::factory()->create();
        $account = $user->socialAccounts()->create([
            'platform' => 'youtube', 'account_name' => 'Test Channel', 'status' => SocialAccount::STATUS_CONNECTED,
            'access_token' => 'fake-access-token', 'refresh_token' => 'fake-refresh-token', 'token_expires_at' => now()->addHour(),
        ]);
        $project = $user->projects()->create(['title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 20]);
        $clip = Clip::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => Clip::STATUS_COMPLETED, 'output_path' => 'clips/x.mp4',
            'cover_path' => 'covers/x.jpg',
        ]);
        $post = SocialPost::create([
            'clip_id' => $clip->id, 'social_account_id' => $account->id, 'platform' => 'youtube',
            'status' => SocialPost::STATUS_UPLOADING, 'scheduled_at' => now(),
        ]);

        Http::fake([
            '*/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.example.com/session']),
            'https://upload.example.com/session' => Http::response(['id' => 'VIDEO123']),
            '*/upload/youtube/v3/thumbnails/set*' => Http::response([]),
        ]);

        (new PublishClipJob($post->id))->handle(app(\App\Services\Social\SocialProviderManager::class), app(\App\Services\Video\CoverGeneratorService::class));

        Bus::assertDispatched(ReapplyYoutubeThumbnailJob::class, function (ReapplyYoutubeThumbnailJob $job) use ($post) {
            return $job->socialPostId === $post->id
                && $job->coverRelativePath === 'covers/x.jpg'
                && $job->videoId === 'VIDEO123'
                && $job->attempt === 1;
        });
    }

    public function test_still_processing_reschedules_another_check_instead_of_setting_the_thumbnail(): void
    {
        Bus::fake();
        [$post, $coverRelativePath] = $this->coverAndPost();

        Http::fake([
            '*/youtube/v3/videos*' => Http::response([
                'items' => [['status' => ['uploadStatus' => 'uploaded'], 'processingDetails' => ['processingStatus' => 'processing']]],
            ]),
        ]);

        (new ReapplyYoutubeThumbnailJob($post->id, $coverRelativePath, 'VIDEO123', attempt: 1))
            ->handle(app(\App\Services\Social\Providers\YouTubeProvider::class));

        // Still processing: must NOT touch thumbnails.set yet...
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'thumbnails/set'));

        // ...and must schedule another check rather than giving up.
        Bus::assertDispatched(ReapplyYoutubeThumbnailJob::class, fn (ReapplyYoutubeThumbnailJob $job) => $job->attempt === 2);
    }

    public function test_finished_processing_re_applies_the_thumbnail_and_stops(): void
    {
        Bus::fake();
        [$post, $coverRelativePath] = $this->coverAndPost();

        Http::fake([
            '*/youtube/v3/videos*' => Http::response([
                'items' => [['status' => ['uploadStatus' => 'processed']]],
            ]),
            '*/upload/youtube/v3/thumbnails/set*' => Http::response([]),
        ]);

        (new ReapplyYoutubeThumbnailJob($post->id, $coverRelativePath, 'VIDEO123', attempt: 2))
            ->handle(app(\App\Services\Social\Providers\YouTubeProvider::class));

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'thumbnails/set')) {
                return false;
            }
            $this->assertStringContainsString('/upload/youtube/v3/thumbnails/set', $request->url());

            return true;
        });

        // Done: no further check is scheduled once the thumbnail sticks for good.
        Bus::assertNotDispatched(ReapplyYoutubeThumbnailJob::class);
    }

    public function test_gives_up_after_the_last_attempt_without_scheduling_forever(): void
    {
        Bus::fake();
        Log::spy();
        [$post, $coverRelativePath] = $this->coverAndPost();

        Http::fake([
            '*/youtube/v3/videos*' => Http::response([
                'items' => [['status' => ['uploadStatus' => 'uploaded'], 'processingDetails' => ['processingStatus' => 'processing']]],
            ]),
        ]);

        // Last configured attempt (5) — must stop rather than scheduling a 6th.
        (new ReapplyYoutubeThumbnailJob($post->id, $coverRelativePath, 'VIDEO123', attempt: 5))
            ->handle(app(\App\Services\Social\Providers\YouTubeProvider::class));

        Bus::assertNotDispatched(ReapplyYoutubeThumbnailJob::class);
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message) => str_contains($message, 'giving up')
        )->once();
    }

    public function test_a_disconnected_account_stops_polling_instead_of_retrying_forever(): void
    {
        Bus::fake();
        [$post, $coverRelativePath] = $this->coverAndPost();
        $post->socialAccount->update(['status' => SocialAccount::STATUS_REVOKED, 'token_expires_at' => now()->subHour(), 'refresh_token' => null]);

        (new ReapplyYoutubeThumbnailJob($post->id, $coverRelativePath, 'VIDEO123', attempt: 1))
            ->handle(app(\App\Services\Social\Providers\YouTubeProvider::class));

        Bus::assertNotDispatched(ReapplyYoutubeThumbnailJob::class);
    }
}
