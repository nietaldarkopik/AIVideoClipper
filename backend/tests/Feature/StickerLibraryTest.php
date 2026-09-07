<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Video\StickerLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The built-in sticker set. It's generated rather than shipped as binaries (see
 * StickerLibrary), so what matters here is that generation is lazy, idempotent,
 * and produces files FFmpeg's overlay path can actually decode.
 */
class StickerLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    public function test_stickers_are_generated_on_first_use_as_decodable_transparent_pngs(): void
    {
        $disk = Storage::disk('media');
        $stickers = app(StickerLibrary::class)->all();

        $this->assertNotEmpty($stickers);

        foreach ($stickers as $sticker) {
            $disk->assertExists($sticker['path']);
        }

        // A real PNG with an alpha channel — a sticker without transparency
        // would overlay as an opaque rectangle over the footage.
        $info = getimagesizefromstring($disk->get($stickers[0]['path']));
        $this->assertSame(IMAGETYPE_PNG, $info[2]);

        $image = imagecreatefromstring($disk->get($stickers[0]['path']));
        $this->assertNotFalse($image);
        // The corner is outside every shape in the set, so it must be clear.
        $this->assertSame(127, (imagecolorat($image, 0, 0) >> 24) & 0x7F, 'the corner should be fully transparent');
        imagedestroy($image);
    }

    public function test_generation_is_idempotent_and_does_not_rewrite_existing_files(): void
    {
        $disk = Storage::disk('media');
        $library = app(StickerLibrary::class);

        $first = $library->all();
        $disk->put($first[0]['path'], 'sentinel');

        $second = $library->all();

        $this->assertSame($first, $second);
        // Already-present files are left alone, so this stays cheap on every
        // request after the first.
        $this->assertSame('sentinel', $disk->get($first[0]['path']));
    }

    public function test_the_endpoint_lists_every_sticker_with_a_servable_url(): void
    {
        $response = $this->actingAs(User::factory()->create())->getJson('/api/stickers')->assertOk();

        $stickers = $response->json('data');
        $this->assertNotEmpty($stickers);
        $this->assertStringStartsWith('stickers/', $stickers[0]['path']);
        $this->assertStringContainsString('/api/media/stickers/', $stickers[0]['url']);
        $this->assertNotEmpty($stickers[0]['name']);
    }

    public function test_the_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/stickers')->assertUnauthorized();
    }
}
