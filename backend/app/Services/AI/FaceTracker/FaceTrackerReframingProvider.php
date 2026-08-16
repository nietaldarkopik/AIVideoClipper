<?php

namespace App\Services\AI\FaceTracker;

use App\Services\AI\Contracts\ReframingProvider;
use App\Services\AI\DTOs\ReframeKeyframe;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Smart-crop via a self-hosted face-tracking service (tools/face-tracker): actually
 * detects and follows the primary speaker's face instead of MockReframingProvider's
 * blind left/right toggle. See that service's README for how tracking works.
 */
class FaceTrackerReframingProvider implements ReframingProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 300,
    ) {
    }

    public function detectCropKeyframes(
        string $videoPath,
        float $clipStart,
        float $clipEnd,
        int $sourceWidth,
        int $sourceHeight,
        string $targetAspectRatio
    ): array {
        $response = Http::timeout($this->timeoutSeconds)
            ->post(rtrim($this->baseUrl, '/') . '/detect-crop', [
                'video_path' => $videoPath,
                'clip_start' => $clipStart,
                'clip_end' => $clipEnd,
                'source_width' => $sourceWidth,
                'source_height' => $sourceHeight,
                'target_aspect_ratio' => $targetAspectRatio,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Face tracker crop detection failed ({$response->status()}): {$response->body()}. " .
                "Is the service running at {$this->baseUrl}? (see tools/face-tracker/README.md)"
            );
        }

        $keyframes = $response->json('keyframes') ?? [];

        if (! is_array($keyframes) || empty($keyframes)) {
            Log::warning('Face tracker returned no keyframes; falling back to a centered static crop', [
                'video_path' => $videoPath,
                'clip_start' => $clipStart,
                'clip_end' => $clipEnd,
            ]);

            return [$this->centeredFallback($sourceWidth, $sourceHeight, $targetAspectRatio)];
        }

        return array_map(fn ($k) => new ReframeKeyframe(
            time: (float) ($k['time'] ?? 0),
            x: (float) ($k['x'] ?? 0),
            y: (float) ($k['y'] ?? 0),
            width: (float) ($k['width'] ?? $sourceWidth),
            height: (float) ($k['height'] ?? $sourceHeight),
            activeSpeaker: $k['active_speaker'] ?? null,
        ), $keyframes);
    }

    private function centeredFallback(int $sourceWidth, int $sourceHeight, string $targetAspectRatio): ReframeKeyframe
    {
        [$arW, $arH] = array_map('intval', explode(':', $targetAspectRatio));
        $targetRatio = $arW / $arH;
        $sourceRatio = $sourceWidth / $sourceHeight;

        if ($targetRatio < $sourceRatio) {
            $cropHeight = $sourceHeight;
            $cropWidth = (int) round($cropHeight * $targetRatio);
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) round($cropWidth / $targetRatio);
        }

        return new ReframeKeyframe(
            time: 0.0,
            x: round(($sourceWidth - $cropWidth) / 2, 1),
            y: round(($sourceHeight - $cropHeight) / 2, 1),
            width: (float) $cropWidth,
            height: (float) $cropHeight,
        );
    }
}
