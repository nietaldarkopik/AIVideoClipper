<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Media;
use App\Support\MediaAccess;
use Illuminate\Http\Request;

/**
 * Turns media-disk paths the client already holds (a layer's image_path, a
 * waveform sidecar, ...) into signed /api/media URLs — but only for paths the
 * caller owns, so this can't be used to mint links to someone else's files.
 */
class MediaSignController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'paths' => ['required', 'array', 'min:1', 'max:100'],
            'paths.*' => ['required', 'string', 'max:1024'],
        ]);

        $urls = [];
        foreach (array_unique($data['paths']) as $path) {
            $urls[$path] = MediaAccess::allows($request->user(), $path) ? Media::url($path) : null;
        }

        return response()->json(['data' => $urls]);
    }
}
