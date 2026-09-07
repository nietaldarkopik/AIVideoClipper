<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Video\FFmpegService;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A small per-user media library on the 'media' disk, for the assets a clip's
 * layers point at: image/logo layers' `image_path` and audio layers'
 * `audio_path` (see LayerCompositionService, which resolves those relative paths
 * through the disk at render time).
 *
 * Deliberately backed by the filesystem rather than a table: every consumer in
 * this app already stores a media asset as a plain relative path string
 * (`image_path`, `audio_path`, `watermark_path`, `disk_path`, ...), nothing
 * needs to join against an upload, and files are the source of truth either way
 * — a row would only add a second place for the two to disagree. Ownership is
 * enforced structurally, by the `uploads/{userId}/` prefix every path must sit
 * under (see assertOwnedPath()).
 */
class MediaUploadController extends Controller
{
    /**
     * Extensions per kind. Deliberately an allowlist of what FFmpeg is actually
     * fed downstream, not a broad "any image/audio" mime check: an .svg passes a
     * naive image check but FFmpeg's image decoders can't read it, so it would
     * upload happily and then silently render nothing.
     */
    private const EXTENSIONS = [
        'image' => ['png', 'jpg', 'jpeg', 'webp', 'gif'],
        'audio' => ['mp3', 'wav', 'm4a', 'aac', 'ogg'],
    ];

    // Kilobytes, matching Laravel's `max:` rule unit.
    private const MAX_SIZE_KB = [
        'image' => 10240,
        'audio' => 51200,
    ];

    /**
     * The user's uploaded assets, newest first. `kind` narrows it to what the
     * caller can actually use (an audio layer has no use for a PNG).
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'kind' => ['sometimes', Rule::in(array_keys(self::EXTENSIONS))],
        ]);

        $kinds = isset($data['kind']) ? [$data['kind']] : array_keys(self::EXTENSIONS);
        $disk = Storage::disk('media');
        $files = [];

        foreach ($kinds as $kind) {
            foreach ($disk->files($this->directory($request, $kind)) as $path) {
                // Generated sidecars live next to the asset they describe — they
                // are not assets in their own right and must never show up in the
                // picker (or be selectable as an audio layer's source).
                if (str_ends_with($path, '.waveform.png')) {
                    continue;
                }

                $waveformPath = preg_replace('/\.[^.]+$/', '', $path).'.waveform.png';
                $files[] = $this->describe($path, $kind, $disk->exists($waveformPath) ? $waveformPath : null);
            }
        }

        usort($files, fn ($a, $b) => strcmp($b['uploaded_at'], $a['uploaded_at']));

        return response()->json(['data' => $files]);
    }

    public function store(Request $request, FFmpegService $ffmpeg)
    {
        $kind = $request->input('kind');
        if (! is_string($kind) || ! isset(self::EXTENSIONS[$kind])) {
            // Validated before the file rules so `max:`/extension messages can be
            // phrased for the specific kind being uploaded.
            return response()->json(['message' => 'The kind field must be one of: image, audio.'], 422);
        }

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_SIZE_KB[$kind],
                'extensions:'.implode(',', self::EXTENSIONS[$kind]),
            ],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        // The original name is kept (slugged) so the picker shows something
        // recognizable, and a short random suffix makes re-uploading a file with
        // the same name a new asset rather than silently replacing whatever else
        // already points at that path.
        $base = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: $kind;
        $filename = Str::limit($base, 60, '').'-'.Str::lower(Str::random(6)).'.'.$extension;

        $path = Storage::disk('media')->putFileAs($this->directory($request, $kind), $file, $filename);

        return response()->json([
            'data' => $this->describe($path, $kind, $this->makeWaveform($ffmpeg, $path, $kind)),
        ], 201);
    }

    /**
     * Renders a waveform PNG beside an uploaded audio file so its block on the
     * timeline can show the shape of the sound, the same way the video track
     * already does for the source's own audio — reusing FFmpegService's existing
     * showwavespic pass rather than introducing a second one.
     *
     * Done inline (a few seconds at most for a music track) instead of via the
     * queue: the picker wants to show the asset immediately, and the waveform is
     * cosmetic. If it fails — a file FFmpeg can't decode, no audio stream,
     * ffmpeg missing — the upload still succeeds and the block just falls back
     * to a plain bar, exactly as it does for a video imported before waveforms
     * existed.
     */
    private function makeWaveform(FFmpegService $ffmpeg, string $path, string $kind): ?string
    {
        if ($kind !== 'audio') {
            return null;
        }

        $disk = Storage::disk('media');
        $waveformPath = preg_replace('/\.[^.]+$/', '', $path).'.waveform.png';

        try {
            $ffmpeg->generateWaveform($disk->path($path), $disk->path($waveformPath));
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return $disk->exists($waveformPath) ? $waveformPath : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(string $path, string $kind, ?string $waveformPath): array
    {
        $disk = Storage::disk('media');

        return [
            'path' => $path,
            'url' => Media::url($path),
            'kind' => $kind,
            'name' => $this->displayName($path),
            'size_bytes' => $disk->size($path),
            'uploaded_at' => date(DATE_ATOM, $disk->lastModified($path)),
            // Null for images, and for audio whose waveform pass didn't produce
            // one — consumers must treat it as optional.
            'waveform_path' => $waveformPath,
            'waveform_url' => Media::url($waveformPath),
        ];
    }

    /**
     * Removes an asset from the library. Any layer still pointing at it keeps its
     * now-dangling path — LayerCompositionService already tolerates that (an
     * image/audio layer whose file is gone is skipped rather than failing the
     * render), and rewriting every clip that referenced it would be a far more
     * surprising side effect of a delete than a layer quietly rendering nothing.
     */
    public function destroy(Request $request)
    {
        $data = $request->validate(['path' => ['required', 'string']]);
        $path = $this->assertOwnedPath($request, $data['path']);

        // Takes the generated waveform sidecar with it, so deleting an asset
        // doesn't leave an orphan PNG behind in the user's uploads directory.
        Storage::disk('media')->delete([$path, preg_replace('/\.[^.]+$/', '', $path).'.waveform.png']);

        return response()->json(['message' => 'Asset deleted.']);
    }

    private function directory(Request $request, string $kind): string
    {
        return "uploads/{$request->user()->id}/{$kind}";
    }

    /**
     * The only authorization this controller needs: a path is the user's if and
     * only if it sits under their own uploads prefix. Traversal is rejected
     * outright rather than normalized, so no `..` can walk out of that prefix.
     */
    private function assertOwnedPath(Request $request, string $path): string
    {
        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        $prefix = "uploads/{$request->user()->id}/";

        if (str_contains($normalized, '..') || ! str_starts_with($normalized, $prefix)) {
            throw new NotFoundHttpException;
        }

        if (! Storage::disk('media')->exists($normalized)) {
            throw new NotFoundHttpException;
        }

        return $normalized;
    }

    /**
     * Strips the random uniqueness suffix back off for display, so a file the
     * user uploaded as "intro-music.mp3" doesn't show up as
     * "intro-music-k3f9a2.mp3" in the picker.
     */
    private function displayName(string $path): string
    {
        $filename = basename($path);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $stem = pathinfo($filename, PATHINFO_FILENAME);

        return preg_replace('/-[a-z0-9]{6}$/', '', $stem).'.'.$extension;
    }
}
