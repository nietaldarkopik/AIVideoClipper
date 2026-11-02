<?php

namespace App\Services\AI\Video;

use App\Services\AI\Contracts\SceneVideoProvider;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Zero-cost "animation": a Ken Burns style zoom/pan over the still image, chosen from
 * keywords in the motion prompt. No API key, deterministic, and exactly as long as
 * the narration — the default so the pipeline works before any video model is set up.
 */
class CameraMotionSceneVideoProvider implements SceneVideoProvider
{
    private const WIDTH = 1080;

    private const HEIGHT = 1920;

    private const FPS = 30;

    // Upscale before zoompan: it samples with integer-ish steps, so working on a
    // larger canvas is what keeps the slow zoom from visibly stepping.
    private const WORK_WIDTH = 2160;

    public function __construct(
        private readonly string $ffmpegBin = 'ffmpeg',
    ) {
    }

    public function animate(string $imagePath, string $motionPrompt, string $outPath, float $targetSeconds): void
    {
        $dir = dirname($outPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $frames = max(1, (int) ceil($targetSeconds * self::FPS));
        $motion = $this->zoompanExpression(mb_strtolower($motionPrompt), $frames);

        $filter = sprintf(
            "scale=%d:-2:force_original_aspect_ratio=increase,crop=%d:%d,zoompan=%s:d=%d:s=%dx%d:fps=%d,format=yuv420p",
            self::WORK_WIDTH,
            self::WORK_WIDTH,
            (int) (self::WORK_WIDTH * self::HEIGHT / self::WIDTH),
            $motion,
            $frames,
            self::WIDTH,
            self::HEIGHT,
            self::FPS,
        );

        $result = Process::timeout(300)->run([
            $this->ffmpegBin, '-y',
            '-i', $imagePath,
            '-vf', $filter,
            '-frames:v', (string) $frames,
            '-c:v', 'libx264', '-preset', 'fast', '-crf', '20', '-an',
            $outPath,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('Camera-motion scene render failed: ' . $result->errorOutput());
        }
    }

    private function zoompanExpression(string $prompt, int $frames): string
    {
        $center = "x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)'";

        if (str_contains($prompt, 'zoom out')) {
            return "z='1.25-0.25*on/{$frames}':{$center}";
        }

        if (str_contains($prompt, 'pan left')) {
            return "z=1.2:x='(iw-iw/zoom)*(1-on/{$frames})':y='ih/2-(ih/zoom/2)'";
        }

        if (str_contains($prompt, 'pan right')) {
            return "z=1.2:x='(iw-iw/zoom)*on/{$frames}':y='ih/2-(ih/zoom/2)'";
        }

        return "z='1+0.25*on/{$frames}':{$center}";
    }
}
