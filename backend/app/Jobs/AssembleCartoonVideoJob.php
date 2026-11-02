<?php

namespace App\Jobs;

use App\Models\CartoonVideo;
use App\Models\Clip;
use App\Models\ContentBrief;
use App\Models\ContentIdea;
use App\Models\Project;
use App\Models\Video;
use App\Services\Video\CartoonVideoStyle;
use App\Services\Video\FFmpegService;
use App\Services\Video\SubtitleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Final step: stitch the finished scenes + narration into one vertical mp4 with
 * burned-in captions, then register it as a Project/Video/Clip so it shows up in the
 * normal clips list and can be scheduled/published through the existing pipeline.
 */
class AssembleCartoonVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const WIDTH = 1080;

    private const HEIGHT = 1920;

    // Words per on-screen caption line — short enough to read at a glance on a phone.
    private const WORDS_PER_CAPTION = 4;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $cartoonVideoId)
    {
    }

    public function handle(FFmpegService $ffmpeg, SubtitleService $subtitles): void
    {
        $video = CartoonVideo::with('scenes')->findOrFail($this->cartoonVideoId);
        $disk = Storage::disk('media');
        $tail = (float) config('cartoon.scene_tail_seconds', 0.35);

        try {
            $timeline = [];
            $captions = [];
            $cursor = 0.0;

            foreach ($video->scenes as $scene) {
                $duration = max(2.0, (float) $scene->audio_duration + $tail);

                $timeline[] = [
                    'clip' => $disk->path($scene->clip_path),
                    'audio' => $disk->path($scene->audio_path),
                    'duration' => $duration,
                ];
                array_push($captions, ...$this->captionSegments($scene->narration, $cursor, (float) $scene->audio_duration));
                $cursor += $duration;
            }

            $dir = "cartoons/{$video->id}";
            $assRelative = "{$dir}/captions.ass";
            $disk->put($assRelative, $subtitles->toAss($captions, CartoonVideoStyle::captionConfig(), self::WIDTH, self::HEIGHT));

            $output = "{$dir}/final.mp4";
            $thumb = "{$dir}/thumb.jpg";

            $ffmpeg->assembleCartoonVideo($timeline, $disk->path($assRelative), $disk->path($output), self::WIDTH, self::HEIGHT);
            $ffmpeg->generateThumbnail($disk->path($output), $disk->path($thumb), 1.0);

            $duration = $ffmpeg->probeDuration($disk->path($output)) ?? $cursor;

            $this->registerClip($video, $output, $disk->exists($thumb) ? $thumb : null, $duration, (int) $disk->size($output));

            $video->update([
                'status' => CartoonVideo::STATUS_COMPLETED,
                'progress' => 100,
                'message' => 'Selesai',
                'output_path' => $output,
                'thumbnail_path' => $disk->exists($thumb) ? $thumb : null,
                'duration_seconds' => $duration,
                'failure_reason' => null,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $video->update([
                'status' => CartoonVideo::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }
    }

    /**
     * Spread a scene's narration over its audio as short caption lines, timed by
     * character share — TTS gives no word timestamps, and an even per-character split
     * tracks speech closely enough for 4-word lines.
     *
     * @return array<int, array{start: float, end: float, text: string}>
     */
    private function captionSegments(string $narration, float $sceneStart, float $audioDuration): array
    {
        $words = preg_split('/\s+/u', trim($narration), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chunks = array_map(fn ($c) => implode(' ', $c), array_chunk($words, self::WORDS_PER_CAPTION));
        $totalChars = max(1, array_sum(array_map('mb_strlen', $chunks)));

        $segments = [];
        $t = $sceneStart;

        foreach ($chunks as $chunk) {
            $span = $audioDuration * mb_strlen($chunk) / $totalChars;
            $segments[] = ['start' => $t, 'end' => $t + $span, 'text' => $chunk];
            $t += $span;
        }

        return $segments;
    }

    private function registerClip(CartoonVideo $cv, string $output, ?string $thumb, float $duration, int $size): void
    {
        $project = $cv->project ?? Project::create([
            'user_id' => $cv->user_id,
            'title' => $cv->title,
            'status' => Project::STATUS_COMPLETED,
            'origin_type' => $cv->source_type === CartoonVideo::SOURCE_CONTENT_BRIEF ? Project::ORIGIN_CONTENT_BRIEF : Project::ORIGIN_CONTENT_IDEA,
            'origin_id' => $cv->source_id,
            'last_edited_at' => now(),
        ]);

        if ($cv->clip) {
            $cv->clip->update([
                'output_path' => $output, 'thumbnail_path' => $thumb, 'cover_path' => $thumb,
                'duration' => $duration, 'end_time' => $duration, 'output_size_bytes' => $size,
                'status' => Clip::STATUS_COMPLETED, 'progress' => 100, 'rendered_at' => now(),
            ]);
            $cv->project_id = $project->id;

            return;
        }

        $source = Video::create([
            'project_id' => $project->id,
            'source_type' => 'upload',
            'original_filename' => 'cartoon-' . $cv->id . '.mp4',
            'disk_path' => $output,
            'thumbnail_path' => $thumb,
            'title' => $cv->title,
            'duration_seconds' => (int) round($duration),
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'file_size_bytes' => $size,
            'status' => 'ready',
        ]);

        [$caption, $hashtags] = $this->socialCopy($cv);

        $clip = Clip::create([
            'project_id' => $project->id,
            'video_id' => $source->id,
            'title' => $cv->title,
            'caption' => $caption,
            'hashtags' => $hashtags,
            'start_time' => 0,
            'end_time' => $duration,
            'duration' => $duration,
            'aspect_ratio' => '9:16',
            'status' => Clip::STATUS_COMPLETED,
            'progress' => 100,
            'output_path' => $output,
            'output_size_bytes' => $size,
            'rendered_at' => now(),
            'thumbnail_path' => $thumb,
            'cover_path' => $thumb,
        ]);

        $cv->update(['project_id' => $project->id, 'clip_id' => $clip->id]);
    }

    /**
     * @return array{0: ?string, 1: array<int, string>}
     */
    private function socialCopy(CartoonVideo $cv): array
    {
        if ($cv->source_type === CartoonVideo::SOURCE_CONTENT_BRIEF) {
            $brief = ContentBrief::find($cv->source_id);

            return [$brief?->narrative_suggested_description, (array) ($brief?->narrative_suggested_hashtags ?? [])];
        }

        $idea = ContentIdea::find($cv->source_id);

        return [$idea?->short_description, array_slice((array) ($idea?->keywords ?? []), 0, 8)];
    }
}
