<?php

namespace App\Services\AI\Mock;

use App\Services\AI\Contracts\ImageGenerationProvider;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Zero-cost placeholder: reuses $fallbackFramePath (a real frame already grabbed
 * from the clip's own video) as-is, byte-identical to this app's pre-this-feature
 * default behavior — critical, since AI_COVER_IMAGE_PROVIDER defaults to mock and
 * must not change the render output of an installation with zero 9Router config.
 * Only draws a synthetic placeholder (via ffmpeg's lavfi color source) in the rare
 * case no fallback frame is available at all.
 */
class MockImageGenerationProvider implements ImageGenerationProvider
{
    public function __construct(
        private readonly string $ffmpegBin = 'ffmpeg',
    ) {
    }

    public function generateCoverImage(string $prompt, string $outputPath, ?string $fallbackFramePath = null): void
    {
        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if ($fallbackFramePath && is_file($fallbackFramePath)) {
            copy($fallbackFramePath, $outputPath);

            return;
        }

        $result = Process::timeout(30)->run([
            $this->ffmpegBin, '-y',
            '-f', 'lavfi', '-i', 'color=c=0x2a2a3d:s=1080x1920',
            '-frames:v', '1',
            $outputPath,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('Mock cover image generation failed: ' . $result->errorOutput());
        }
    }
}
