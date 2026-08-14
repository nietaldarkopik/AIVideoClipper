<?php

namespace App\Jobs;

use App\Models\ProcessingJob;
use App\Models\Project;
use App\Models\Video;
use App\Services\Video\FFmpegService;
use App\Services\Video\UrlVideoDownloader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 1800;

    public function __construct(public int $videoId)
    {
    }

    public function handle(FFmpegService $ffmpeg, UrlVideoDownloader $downloader): void
    {
        $video = Video::findOrFail($this->videoId);
        $disk = Storage::disk('media');

        $processingJob = ProcessingJob::create([
            'project_id' => $video->project_id,
            'video_id' => $video->id,
            'type' => 'import_video',
            'status' => ProcessingJob::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $video->update(['status' => 'processing']);

            if ($video->source_type !== 'upload') {
                $processingJob->markProgress(10, 'Downloading video...');
                $destDir = $disk->path("videos/{$video->id}");
                $downloaded = $downloader->download($video->source_url, $destDir);

                $relativePath = 'videos/' . $video->id . '/' . basename($downloaded['path']);
                $metadata = $video->metadata ?? [];
                if ($downloaded['captions']) {
                    $metadata['captions_srt_path'] = 'videos/' . $video->id . '/' . basename($downloaded['captions']['path']);
                    $metadata['captions_language'] = $downloaded['captions']['language'];
                }

                $video->update([
                    'disk_path' => $relativePath,
                    'title' => $video->title ?: $downloaded['title'],
                    'original_filename' => basename($downloaded['path']),
                    'metadata' => $metadata,
                ]);
            }

            $processingJob->markProgress(40, 'Reading video metadata...');
            $fullPath = $disk->path($video->disk_path);
            $probe = $ffmpeg->probe($fullPath);

            $video->update([
                'duration_seconds' => $probe['duration'] ? (int) round($probe['duration']) : null,
                'width' => $probe['width'],
                'height' => $probe['height'],
                'file_size_bytes' => $probe['size'],
            ]);

            $processingJob->markProgress(75, 'Generating thumbnail...');
            $thumbRelative = "videos/{$video->id}/thumbnail.jpg";
            $ffmpeg->generateThumbnail(
                $fullPath,
                $disk->path($thumbRelative),
                min(1.0, max(0.1, ($probe['duration'] ?? 10) * 0.1))
            );

            $video->update([
                'thumbnail_path' => $thumbRelative,
                'title' => $video->title ?: pathinfo($video->original_filename ?? 'Untitled', PATHINFO_FILENAME),
                'status' => 'ready',
            ]);

            $processingJob->markCompleted('Video ready');

            // Import is done; the project sits idle (draft) until the user explicitly
            // clicks "Analyze Video". Without this it stays stuck on "uploading"
            // forever and the frontend polls indefinitely.
            Project::where('id', $video->project_id)
                ->where('status', Project::STATUS_UPLOADING)
                ->update(['status' => Project::STATUS_DRAFT]);
        } catch (Throwable $e) {
            $video->update(['status' => 'failed', 'failure_reason' => $e->getMessage()]);
            $processingJob->markFailed($e->getMessage());
            Project::where('id', $video->project_id)
                ->where('status', Project::STATUS_UPLOADING)
                ->update(['status' => Project::STATUS_FAILED, 'failure_reason' => $e->getMessage()]);

            throw $e;
        }
    }
}
