<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use App\Support\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * /api/media is anonymous (media tags can't send a token), so it must be
 * gated by expiring signatures, and POST /api/media/sign must only mint
 * signatures for paths the caller owns.
 */
class MediaSigningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        Storage::disk('media')->put('videos/1/source.mp4', 'video-bytes');
    }

    private function videoFor(User $user): Video
    {
        $project = Project::create(['user_id' => $user->id, 'title' => 'P']);

        return Video::create(['project_id' => $project->id, 'source_type' => 'upload', 'status' => 'ready', 'disk_path' => 'videos/1/source.mp4']);
    }

    private function relative(string $url): string
    {
        return substr($url, strlen(rtrim(config('app.url'), '/')));
    }

    public function test_unsigned_request_is_forbidden(): void
    {
        $this->get('/api/media/videos/1/source.mp4')->assertForbidden();
    }

    public function test_signed_url_streams_and_honours_range(): void
    {
        $this->get($this->relative(Media::url('videos/1/source.mp4')))->assertOk();
        $this->get($this->relative(Media::url('videos/1/source.mp4')), ['Range' => 'bytes=0-3'])->assertStatus(206);
    }

    public function test_signature_is_bound_to_the_path(): void
    {
        Storage::disk('media')->put('videos/2/source.mp4', 'other');
        [$query] = array_slice(explode('?', Media::url('videos/1/source.mp4')), 1);

        $this->get("/api/media/videos/2/source.mp4?{$query}")->assertForbidden();
    }

    public function test_tampered_or_expired_signature_is_forbidden(): void
    {
        $query = explode('?', Media::url('videos/1/source.mp4'))[1];
        parse_str($query, $q);

        $this->get('/api/media/videos/1/source.mp4?' . http_build_query([...$q, 'expires' => $q['expires'] + 1]))->assertForbidden();

        $expired = time() - 10;
        $this->assertFalse(Media::valid('videos/1/source.mp4', $expired, hash_hmac('sha256', "videos/1/source.mp4|{$expired}", (string) config('app.key'))));
    }

    public function test_traversal_is_rejected_even_with_a_valid_signature(): void
    {
        $this->get($this->relative(Media::url('videos/1/../../.env')))->assertNotFound();
    }

    public function test_sign_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/media/sign', ['paths' => ['videos/1/source.mp4']])->assertUnauthorized();
    }

    public function test_sign_endpoint_only_signs_owned_paths(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $this->videoFor($owner);

        $paths = ['videos/1/source.mp4', "uploads/{$owner->id}/image/a.png", 'stickers/x.png', 'nope/1/x.mp4'];

        $mine = $this->actingAs($owner)->postJson('/api/media/sign', ['paths' => $paths])->assertOk()->json('data');
        $this->assertNotNull($mine['videos/1/source.mp4']);
        $this->assertNotNull($mine["uploads/{$owner->id}/image/a.png"]);
        $this->assertNotNull($mine['stickers/x.png']);
        $this->assertNull($mine['nope/1/x.mp4']);

        $theirs = $this->actingAs($stranger)->postJson('/api/media/sign', ['paths' => $paths])->assertOk()->json('data');
        $this->assertNull($theirs['videos/1/source.mp4']);
        $this->assertNull($theirs["uploads/{$owner->id}/image/a.png"]);
        $this->assertNotNull($theirs['stickers/x.png']);
    }
}
