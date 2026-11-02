<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClipResource;
use App\Models\Clip;
use App\Models\Project;
use App\Models\Video;
use App\Services\Video\FFmpegService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Register an already-finished video as a ready-to-publish clip, without running it through
 * the analyze/render pipeline. It is the HTTP counterpart of `clips:publish-file`, for
 * videos produced elsewhere. It only REGISTERS the clip: publishing is a separate, explicit
 * POST /social-posts, so importing a file never puts anything on a public platform.
 */
class ClipImportController extends Controller
{
    private const EXTENSIONS = ['mp4', 'mov', 'webm', 'mkv'];

    public function __invoke(Request $request, FFmpegService $ffmpeg)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm,video/x-matroska', 'max:' . (int) config('integrations.clip_import.max_mb', 2048) * 1024],
            'title' => ['required', 'string', 'max:255'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'hashtags' => ['sometimes', 'array', 'max:30'],
            'hashtags.*' => ['string', 'max:100'],
            'project_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $user = $request->user();
        $file = $request->file('file');

        // The mime check above trusts the client's header; ffprobe is what actually tells us
        // whether this is a playable video, and gives the real size and length.
        $probe = $ffmpeg->probe($file->getRealPath());
        if (! $probe['duration'] || ! $probe['width'] || ! $probe['height']) {
            return response()->json(['message' => 'The file is not a playable video.', 'errors' => ['file' => ['The file is not a playable video.']]], 422);
        }

        $maxSeconds = (int) config('integrations.clip_import.max_duration_seconds', 10800);
        if ($probe['duration'] > $maxSeconds) {
            return response()->json(['message' => "The video is longer than the {$maxSeconds}s limit.", 'errors' => ['file' => ["The video is longer than the {$maxSeconds}s limit."]]], 422);
        }

        $project = null;
        if (! empty($data['project_id'])) {
            // A missing or someone else's project is reported as 404, like every other route.
            $project = Project::where('user_id', $user->id)->find($data['project_id']);
            abort_if(! $project, 404);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        $ext = in_array($ext, self::EXTENSIONS, true) ? $ext : 'mp4';
        $disk = Storage::disk('media');
        $duration = (float) $probe['duration'];
        $stored = [];

        try {
            $clip = DB::transaction(function () use ($data, $user, $project, $file, $ext, $probe, $duration, $disk, $ffmpeg, &$stored) {
                $project ??= Project::create([
                    'user_id' => $user->id,
                    'title' => $data['title'],
                    'status' => Project::STATUS_COMPLETED,
                    'last_edited_at' => now(),
                ]);

                $video = Video::create([
                    'project_id' => $project->id,
                    'source_type' => 'upload',
                    'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'disk_path' => 'pending',
                    'title' => $data['title'],
                    'duration_seconds' => (int) round($duration),
                    'width' => $probe['width'],
                    'height' => $probe['height'],
                    'file_size_bytes' => $probe['size'] ?? $file->getSize(),
                    'status' => 'ready',
                    'metadata' => ['imported_clip' => true],
                ]);

                $clip = Clip::create([
                    'project_id' => $project->id,
                    'video_id' => $video->id,
                    'title' => $data['title'],
                    'caption' => $data['caption'] ?? null,
                    'hashtags' => $data['hashtags'] ?? [],
                    'start_time' => 0,
                    'end_time' => $duration,
                    'duration' => $duration,
                    'aspect_ratio' => $this->nearestAspect($probe['width'], $probe['height']),
                    'status' => Clip::STATUS_COMPLETED,
                    'progress' => 100,
                    'output_path' => 'pending',
                    'rendered_at' => now(),
                ]);

                // The file lives under clips/{clipId}/ so MediaAccess already authorizes its
                // owner and /media/sign can refresh the URL; the source Video points at the
                // same file instead of storing a 2 GB duplicate.
                $videoPath = $disk->putFileAs("clips/{$clip->id}", $file, "imported.{$ext}");
                $stored[] = $videoPath;

                $thumb = "clips/{$clip->id}/thumb.jpg";
                $ffmpeg->generateThumbnail($disk->path($videoPath), $disk->path($thumb), min(1.0, $duration / 2));
                $hasThumb = $disk->exists($thumb);
                $hasThumb && $stored[] = $thumb;

                $video->update(['disk_path' => $videoPath, 'thumbnail_path' => $hasThumb ? $thumb : null]);
                $clip->update([
                    'output_path' => $videoPath,
                    'output_size_bytes' => $disk->size($videoPath),
                    'thumbnail_path' => $hasThumb ? $thumb : null,
                    'cover_path' => $hasThumb ? $thumb : null,
                ]);

                return $clip;
            });
        } catch (Throwable $e) {
            // A rolled-back transaction leaves no rows, but the file was already written.
            foreach ($stored as $path) {
                $disk->delete($path);
            }

            throw $e;
        }

        return ClipResource::make($clip->load('project'))->response()->setStatusCode(201);
    }

    /** The clip model only knows three aspect ratios; pick the closest to the real one. */
    private function nearestAspect(int $width, int $height): string
    {
        $ratio = $width / max(1, $height);
        $options = ['9:16' => 9 / 16, '1:1' => 1.0, '16:9' => 16 / 9];
        uasort($options, fn ($a, $b) => abs($a - $ratio) <=> abs($b - $ratio));

        return array_key_first($options);
    }
}
