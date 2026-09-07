<?php

namespace Tests\Feature;

use App\Models\Clip;
use App\Models\Project;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Providers\YouTubeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression test for the "video publishes but the custom thumbnail never sticks"
 * bug: thumbnails.set is a "simple media upload" — the request body must be the
 * raw image bytes with Content-Type set to the image mime type, POSTed to the
 * /upload/ host, not a multipart field POSTed to the plain API host. Sending it
 * as a multipart field (the previous implementation) made Google reject every
 * single request with a 400 mediaBodyRequired error, silently logged and never
 * surfaced to the user — who then had to pick a thumbnail from the video or
 * upload it by hand on YouTube itself.
 */
class YouTubeThumbnailUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_thumbnail_is_uploaded_as_a_raw_body_to_the_upload_host(): void
    {
        $coverPath = tempnam(sys_get_temp_dir(), 'cover').'.jpg';
        // A minimal but real JPEG signature, not an empty file — the provider stats
        // and mime-sniffs it before uploading.
        file_put_contents($coverPath, base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/wAALCAABAAEBAREA/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAA/AKgH/9k='));

        $user = User::factory()->create();
        $account = $user->socialAccounts()->create([
            'platform' => 'youtube',
            'account_name' => 'Test Channel',
            'status' => SocialAccount::STATUS_CONNECTED,
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addHour(),
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
            'status' => SocialPost::STATUS_UPLOADING, 'scheduled_at' => now(),
        ]);
        $post->setRelation('clip', $clip);
        $post->setRelation('socialAccount', $account);

        $videoFilePath = tempnam(sys_get_temp_dir(), 'video').'.mp4';
        file_put_contents($videoFilePath, 'fake video bytes');

        Http::fake([
            '*/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.example.com/session']),
            'https://upload.example.com/session' => Http::response(['id' => 'VIDEO123']),
            '*/upload/youtube/v3/thumbnails/set*' => Http::response(['items' => [['default' => ['url' => 'https://img.example/x.jpg']]]]),
        ]);

        $result = (new YouTubeProvider)->publish($post, $videoFilePath, $coverPath);

        $this->assertTrue($result['success']);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/thumbnails/set')) {
                return false;
            }

            // Must hit the /upload/ host — the plain API host 404s for this endpoint.
            $this->assertStringContainsString('/upload/youtube/v3/thumbnails/set', $request->url());
            $this->assertStringContainsString('videoId=VIDEO123', $request->url());

            // Must be a raw-body simple upload, never multipart/form-data — that shape
            // is exactly what made Google respond with "mediaBodyRequired".
            $this->assertStringNotContainsString('multipart/form-data', (string) $request->header('Content-Type')[0]);
            $this->assertSame('image/jpeg', $request->header('Content-Type')[0]);
            $this->assertNotEmpty((string) $request->body());

            return true;
        });

        @unlink($coverPath);
        @unlink($videoFilePath);
    }

    public function test_a_failed_thumbnail_upload_does_not_fail_the_whole_publish(): void
    {
        $coverPath = tempnam(sys_get_temp_dir(), 'cover').'.jpg';
        file_put_contents($coverPath, base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/wAALCAABAAEBAREA/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAA/AKgH/9k='));

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
            'status' => SocialPost::STATUS_UPLOADING, 'scheduled_at' => now(),
        ]);
        $post->setRelation('clip', $clip);
        $post->setRelation('socialAccount', $account);

        $videoFilePath = tempnam(sys_get_temp_dir(), 'video').'.mp4';
        file_put_contents($videoFilePath, 'fake video bytes');

        Http::fake([
            '*/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.example.com/session']),
            'https://upload.example.com/session' => Http::response(['id' => 'VIDEO123']),
            '*/upload/youtube/v3/thumbnails/set*' => Http::response(['error' => ['message' => 'channel not verified']], 403),
        ]);

        $result = (new YouTubeProvider)->publish($post, $videoFilePath, $coverPath);

        // A thumbnail failure (e.g. an unverified channel, still a real possible
        // cause) must never sink the whole publish — the video itself already
        // uploaded successfully.
        $this->assertTrue($result['success']);
        $this->assertSame('VIDEO123', $result['external_post_id']);

        @unlink($coverPath);
        @unlink($videoFilePath);
    }
}
