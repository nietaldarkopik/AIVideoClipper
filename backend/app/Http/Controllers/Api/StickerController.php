<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Video\StickerLibrary;
use App\Support\Media;

/**
 * The built-in sticker set the editor's Stickers panel browses. Read-only and
 * shared by every user — unlike MediaUploadController's per-user library, these
 * aren't owned by anyone, so there's nothing to scope or authorize beyond being
 * signed in.
 *
 * A sticker is just an image on the media disk: picking one adds an ordinary
 * image layer pointing at its path, which is why nothing here (or in the render
 * pipeline) needs a distinct "sticker" concept.
 */
class StickerController extends Controller
{
    public function index(StickerLibrary $library)
    {
        $stickers = array_map(fn (array $sticker) => [
            'path' => $sticker['path'],
            'url' => Media::url($sticker['path']),
            'name' => $sticker['name'],
            'shape' => $sticker['shape'],
            'color' => $sticker['color'],
        ], $library->all());

        return response()->json(['data' => $stickers]);
    }
}
