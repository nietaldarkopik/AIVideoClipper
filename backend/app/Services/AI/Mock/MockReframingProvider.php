<?php

namespace App\Services\AI\Mock;

use App\Services\AI\Contracts\ReframingProvider;
use App\Services\AI\DTOs\ReframeKeyframe;

class MockReframingProvider implements ReframingProvider
{
    /**
     * Fakes face/speaker tracking: computes a centered crop box sized to the target
     * aspect ratio, then gently alternates its horizontal anchor over time to
     * simulate "dynamic camera switching" between two speakers.
     *
     * @return ReframeKeyframe[]
     */
    public function detectCropKeyframes(
        string $videoPath,
        float $clipStart,
        float $clipEnd,
        int $sourceWidth,
        int $sourceHeight,
        string $targetAspectRatio
    ): array {
        [$arW, $arH] = array_map('intval', explode(':', $targetAspectRatio));
        $targetRatio = $arW / $arH;
        $sourceRatio = $sourceWidth / $sourceHeight;

        if ($targetRatio < $sourceRatio) {
            // Crop width, keep full height (typical landscape -> 9:16 case).
            $cropHeight = $sourceHeight;
            $cropWidth = (int) round($cropHeight * $targetRatio);
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) round($cropWidth / $targetRatio);
        }

        $centerX = ($sourceWidth - $cropWidth) / 2;
        $y = max(0, ($sourceHeight - $cropHeight) / 2);

        $switchInterval = 10.0;
        $keyframes = [];
        $t = $clipStart;
        $speakerToggle = false;
        $maxShift = min($centerX, $sourceWidth * 0.12);

        while ($t < $clipEnd) {
            $x = $speakerToggle ? max(0, $centerX - $maxShift) : min($sourceWidth - $cropWidth, $centerX + $maxShift);

            $keyframes[] = new ReframeKeyframe(
                time: round($t - $clipStart, 2),
                x: round($x, 1),
                y: round($y, 1),
                width: (float) $cropWidth,
                height: (float) $cropHeight,
                activeSpeaker: $speakerToggle ? 'B' : 'A',
            );

            $speakerToggle = ! $speakerToggle;
            $t += $switchInterval;
        }

        if (empty($keyframes)) {
            $keyframes[] = new ReframeKeyframe(0.0, round($centerX, 1), round($y, 1), (float) $cropWidth, (float) $cropHeight, 'A');
        }

        return $keyframes;
    }
}
