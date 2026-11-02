<?php

namespace App\Jobs;

use App\Models\CartoonScene;
use App\Models\CartoonVideo;
use App\Services\AI\Contracts\SceneVideoProvider;
use App\Services\AI\Contracts\TextToSpeechProvider;
use App\Services\AI\Support\FallbackImageGenerationProvider;
use App\Services\AI\Support\FallbackSceneVideoProvider;
use App\Services\Video\CartoonVideoStyle;
use App\Services\Video\FFmpegService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Builds one scene: image -> narration audio -> animated clip. One job per scene (not
 * one for the whole video) so a failed scene can be retried alone and the paid steps
 * already finished for the others are never repeated — each step is skipped when its
 * output file is already on the scene.
 *
 * The last scene job to finish decides what happens next (see finishIfAllScenesDone):
 * all done -> AssembleCartoonVideoJob, any failed -> needs_attention.
 */
class RenderCartoonSceneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // The AI video step polls the upstream job for up to 10 minutes on its own.
    public int $timeout = 1200;

    public function __construct(public int $sceneId)
    {
    }

    public function handle(TextToSpeechProvider $tts, SceneVideoProvider $sceneVideo, FFmpegService $ffmpeg): void
    {
        $scene = CartoonScene::with('cartoonVideo')->findOrFail($this->sceneId);
        $video = $scene->cartoonVideo;

        if (CartoonVideo::whereKey($video->id)->value('cancel_requested')) {
            $scene->update(['status' => CartoonScene::STATUS_PENDING]);
            $this->finishIfAllScenesDone($video->id);

            return;
        }

        $scene->update(['status' => CartoonScene::STATUS_PROCESSING, 'error' => null]);

        $disk = Storage::disk('media');
        $dir = "cartoons/{$video->id}";
        $stem = sprintf('scene_%02d', $scene->position);

        try {
            if (! $scene->image_path || ! $disk->exists($scene->image_path)) {
                $raw = $disk->path("{$dir}/{$stem}_raw.jpg");
                $final = "{$dir}/{$stem}.jpg";

                $images = app('cartoon.image');
                $images->generateCoverImage(
                    CartoonVideoStyle::imagePrompt($video->style, $video->character_description, $scene->visual_prompt),
                    $raw,
                );
                $ffmpeg->fitImageToVertical($raw, $disk->path($final));
                @unlink($raw);

                $scene->update([
                    'image_path' => $final,
                    'image_provider' => $images instanceof FallbackImageGenerationProvider ? $images->lastUsed() : null,
                    'clip_path' => null,
                ]);
            }

            if (! $scene->audio_path || ! $disk->exists($scene->audio_path) || ! $scene->audio_duration) {
                $audio = "{$dir}/{$stem}.mp3";
                $tts->synthesize($scene->narration, $disk->path($audio), $video->voice);

                $duration = $ffmpeg->probeDuration($disk->path($audio));
                if (! $duration) {
                    throw new RuntimeException('Narasi scene ini gagal dibuat (durasi audio tidak terbaca).');
                }

                $scene->update(['audio_path' => $audio, 'audio_duration' => $duration, 'clip_path' => null]);
            }

            if (! $scene->clip_path || ! $disk->exists($scene->clip_path)) {
                $clip = "{$dir}/{$stem}.mp4";
                $sceneVideo->animate(
                    $disk->path($scene->image_path),
                    (string) ($scene->motion_prompt ?: 'Slow zoom in'),
                    $disk->path($clip),
                    $scene->audio_duration + (float) config('cartoon.scene_tail_seconds', 0.35),
                );

                $scene->update([
                    'clip_path' => $clip,
                    'clip_provider' => $sceneVideo instanceof FallbackSceneVideoProvider ? $sceneVideo->lastUsed() : null,
                ]);
            }

            $scene->update(['status' => CartoonScene::STATUS_DONE]);
        } catch (Throwable $e) {
            $scene->update(['status' => CartoonScene::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000)]);
        }

        $this->finishIfAllScenesDone($video->id);
    }

    /**
     * Runs after every scene job; the row lock plus the status guard make sure exactly
     * one of the concurrent finishers moves the video on, even with several queue workers.
     */
    private function finishIfAllScenesDone(int $videoId): void
    {
        DB::transaction(function () use ($videoId) {
            $video = CartoonVideo::whereKey($videoId)->lockForUpdate()->first();

            if (! $video || $video->status !== CartoonVideo::STATUS_RENDERING) {
                return;
            }

            $counts = $video->scenes()->reorder()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
            $total = (int) $counts->sum();
            $done = (int) ($counts[CartoonScene::STATUS_DONE] ?? 0);
            $failed = (int) ($counts[CartoonScene::STATUS_FAILED] ?? 0);
            $pending = $total - $done - $failed;

            $video->progress = 10 + (int) round(75 * ($done + $failed) / max($total, 1));

            if ($pending > 0) {
                $video->message = "Merender scene {$done}/{$total}...";
                $video->save();

                return;
            }

            if ($video->cancel_requested) {
                $video->fill(['status' => CartoonVideo::STATUS_CANCELLED, 'message' => 'Dibatalkan.', 'finished_at' => now()])->save();

                return;
            }

            if ($failed > 0) {
                $video->fill([
                    'status' => CartoonVideo::STATUS_NEEDS_ATTENTION,
                    'message' => "{$failed} scene gagal — coba render ulang scene yang gagal.",
                ])->save();

                return;
            }

            $video->fill(['status' => CartoonVideo::STATUS_ASSEMBLING, 'progress' => 88, 'message' => 'Merakit video...'])->save();
            AssembleCartoonVideoJob::dispatch($video->id)->afterCommit();
        });
    }
}
