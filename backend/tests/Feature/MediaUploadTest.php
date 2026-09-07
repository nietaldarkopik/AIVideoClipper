<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The per-user media library that backs image/logo/audio layers. Ownership here
 * is structural — a path is the user's only if it sits under their own
 * `uploads/{id}/` prefix — so these cover that boundary as well as the happy
 * path. See MediaUploadController.
 */
class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    public function test_uploads_an_image_and_returns_a_reusable_relative_path(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/media-uploads', [
            'kind' => 'image',
            'file' => UploadedFile::fake()->image('My Logo.png'),
        ]);

        $response->assertCreated();
        $path = $response->json('data.path');

        // Scoped to the owner, and the path is disk-relative — exactly what a
        // layer's image_path holds and what LayerCompositionService resolves.
        $this->assertStringStartsWith("uploads/{$user->id}/image/", $path);
        Storage::disk('media')->assertExists($path);
        // The random uniqueness suffix is hidden from the picker's label.
        $this->assertSame('my-logo.png', $response->json('data.name'));
    }

    public function test_uploading_the_same_filename_twice_creates_two_distinct_assets(): void
    {
        $user = User::factory()->create();

        $first = $this->actingAs($user)->postJson('/api/media-uploads', [
            'kind' => 'audio', 'file' => UploadedFile::fake()->create('track.mp3', 128),
        ])->json('data.path');
        $second = $this->actingAs($user)->postJson('/api/media-uploads', [
            'kind' => 'audio', 'file' => UploadedFile::fake()->create('track.mp3', 128),
        ])->json('data.path');

        // Otherwise the second upload would silently replace whatever the first
        // one's path was already wired into.
        $this->assertNotSame($first, $second);
        Storage::disk('media')->assertExists($first);
        Storage::disk('media')->assertExists($second);
    }

    public function test_rejects_a_format_ffmpeg_cannot_decode(): void
    {
        $user = User::factory()->create();

        // Passes a naive "is an image" check but has no FFmpeg image decoder —
        // it would upload happily and then render nothing.
        $this->actingAs($user)->postJson('/api/media-uploads', [
            'kind' => 'image', 'file' => UploadedFile::fake()->create('logo.svg', 8, 'image/svg+xml'),
        ])->assertStatus(422);

        $this->actingAs($user)->postJson('/api/media-uploads', [
            'kind' => 'audio', 'file' => UploadedFile::fake()->create('song.flac', 64),
        ])->assertStatus(422);
    }

    public function test_rejects_an_unknown_kind(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/media-uploads', [
            'kind' => 'video', 'file' => UploadedFile::fake()->create('clip.mp4', 64),
        ])->assertStatus(422);
    }

    public function test_listing_is_scoped_to_the_owner_and_filterable_by_kind(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner)->postJson('/api/media-uploads', [
            'kind' => 'image', 'file' => UploadedFile::fake()->image('a.png'),
        ])->assertCreated();
        $this->actingAs($owner)->postJson('/api/media-uploads', [
            'kind' => 'audio', 'file' => UploadedFile::fake()->create('b.mp3', 64),
        ])->assertCreated();
        $this->actingAs($other)->postJson('/api/media-uploads', [
            'kind' => 'image', 'file' => UploadedFile::fake()->image('secret.png'),
        ])->assertCreated();

        $all = $this->actingAs($owner)->getJson('/api/media-uploads')->assertOk()->json('data');
        $this->assertCount(2, $all);

        $audioOnly = $this->actingAs($owner)->getJson('/api/media-uploads?kind=audio')->assertOk()->json('data');
        $this->assertCount(1, $audioOnly);
        $this->assertSame('b.mp3', $audioOnly[0]['name']);

        // The other user's asset is never listed here.
        $this->assertEmpty(array_filter($all, fn ($f) => str_contains($f['name'], 'secret')));
    }

    public function test_a_generated_waveform_sidecar_is_not_listed_as_an_asset_of_its_own(): void
    {
        $user = User::factory()->create();
        $disk = Storage::disk('media');

        $disk->put("uploads/{$user->id}/audio/track-abc123.mp3", 'x');
        // What makeWaveform() writes beside an uploaded audio file.
        $disk->put("uploads/{$user->id}/audio/track-abc123.waveform.png", 'x');

        $files = $this->actingAs($user)->getJson('/api/media-uploads?kind=audio')->assertOk()->json('data');

        $this->assertCount(1, $files, 'the sidecar must not appear as a selectable asset');
        $this->assertSame("uploads/{$user->id}/audio/track-abc123.mp3", $files[0]['path']);
        $this->assertSame("uploads/{$user->id}/audio/track-abc123.waveform.png", $files[0]['waveform_path']);
    }

    public function test_deleting_an_asset_also_removes_its_waveform_sidecar(): void
    {
        $user = User::factory()->create();
        $disk = Storage::disk('media');
        $audio = "uploads/{$user->id}/audio/track-abc123.mp3";
        $waveform = "uploads/{$user->id}/audio/track-abc123.waveform.png";

        $disk->put($audio, 'x');
        $disk->put($waveform, 'x');

        $this->actingAs($user)->deleteJson('/api/media-uploads?path='.urlencode($audio))->assertOk();

        $disk->assertMissing($audio);
        $disk->assertMissing($waveform);
    }

    public function test_cannot_delete_another_users_asset(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $path = $this->actingAs($owner)->postJson('/api/media-uploads', [
            'kind' => 'image', 'file' => UploadedFile::fake()->image('a.png'),
        ])->json('data.path');

        $this->actingAs($attacker)->deleteJson('/api/media-uploads', ['path' => $path])->assertNotFound();
        Storage::disk('media')->assertExists($path);

        $this->actingAs($owner)->deleteJson('/api/media-uploads', ['path' => $path])->assertOk();
        Storage::disk('media')->assertMissing($path);
    }

    public function test_rejects_a_traversal_attempt_out_of_the_uploads_prefix(): void
    {
        $user = User::factory()->create();
        Storage::disk('media')->put('clips/9/output.mp4', 'x');

        $this->actingAs($user)
            ->deleteJson('/api/media-uploads', ['path' => "uploads/{$user->id}/../../clips/9/output.mp4"])
            ->assertNotFound();

        Storage::disk('media')->assertExists('clips/9/output.mp4');
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/media-uploads', [
            'kind' => 'image', 'file' => UploadedFile::fake()->image('a.png'),
        ])->assertUnauthorized();
    }
}
