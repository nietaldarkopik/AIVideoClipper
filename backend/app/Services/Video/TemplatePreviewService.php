<?php

namespace App\Services\Video;

use App\Models\Template;
use App\Models\Video;
use App\Services\AI\Contracts\ReframingProvider;
use App\Support\Media;
use Illuminate\Support\Facades\Storage;

/**
 * Renders a real, server-side sample clip for a template's picker card —
 * exactly the same FFmpegService::renderClip() pipeline RenderClipJob uses
 * for a real clip, just pointed at one fixed stock source video instead of a
 * user's own, and with no per-clip concerns (silence trimming, reactions,
 * intro/outro, multi-segment concat) since there is no real clip involved.
 *
 * Every template previews the same source window so the only thing that
 * differs between two preview videos is the template's own config (caption
 * style, layers, crop/video-region, effects) — the actual currently-published
 * TemplateVersion config, not a description of it.
 */
class TemplatePreviewService
{
    // Any project's Video works here — this one just happens to already have
    // a transcript with word-level timestamps, so captions preview with real
    // word-by-word highlighting instead of a placeholder sentence. Public so
    // the template editor's live (client-side) preview can point its raw
    // <video> background at the exact same footage this service renders
    // from — see demoSource() below.
    public const DEMO_VIDEO_ID = 81;

    public const PREVIEW_START = 2.0;

    public const PREVIEW_DURATION = 8.0;

    public function __construct(
        private readonly FFmpegService $ffmpeg,
        private readonly SubtitleService $subtitleService,
        private readonly ReframingProvider $reframing,
    ) {}

    /**
     * The raw (unrendered) demo source clip the template editor's live preview
     * plays behind its CSS-driven caption/layer overlay — same source video
     * and trim window generate() burns a real preview from, just without any
     * of the actual template config applied yet, since that's still being
     * edited. Lets an admin see real footage react to effect/crop changes
     * instantly, without waiting on an FFmpeg render for every keystroke.
     *
     * @return array{url: string, start: float, duration: float, width: int, height: int}
     */
    public static function demoSource(): array
    {
        $video = Video::findOrFail(self::DEMO_VIDEO_ID);

        return [
            'url' => Media::url($video->disk_path),
            'start' => self::PREVIEW_START,
            'duration' => min((float) $video->duration_seconds - 0.5, self::PREVIEW_START + self::PREVIEW_DURATION) - self::PREVIEW_START,
            'width' => (int) $video->width,
            'height' => (int) $video->height,
        ];
    }

    public function generate(Template $template): string
    {
        $disk = Storage::disk('media');
        $video = Video::findOrFail(self::DEMO_VIDEO_ID);
        $sourcePath = $disk->path($video->disk_path);

        $config = $template->currentVersion?->config ?? DefaultTemplateConfig::config();
        $captionConfig = array_merge(DefaultTemplateConfig::config()['caption'], $config['caption'] ?? []);
        $effectConfig = array_merge(DefaultTemplateConfig::config()['effects'], $config['effects'] ?? []);

        [$targetWidth, $targetHeight] = ($template->resolution_width && $template->resolution_height)
            ? [$template->resolution_width, $template->resolution_height]
            : AspectRatio::resolution($template->aspect_ratio);

        $start = self::PREVIEW_START;
        $end = min((float) $video->duration_seconds - 0.5, $start + self::PREVIEW_DURATION);

        $keyframes = [];
        try {
            $detected = $this->reframing->detectCropKeyframes(
                $sourcePath,
                $start,
                $end,
                (int) $video->width,
                (int) $video->height,
                $template->aspect_ratio
            );
            $keyframes = array_map(fn ($k) => $k->toArray(), $detected);
        } catch (\Throwable) {
            // Center-crop fallback (renderClip() with empty keyframes) beats
            // failing the whole preview over framing.
        }

        $assPath = null;
        $words = $video->transcript?->words ?? [];
        if (! empty($words)) {
            $segments = $this->subtitleService->buildClipSegments(
                $words,
                $start,
                $end,
                (int) ($captionConfig['words_per_line'] ?? 3)
            );
            $ass = $this->subtitleService->toAss($segments, $captionConfig, $targetWidth, $targetHeight);
            $assRelative = "templates/{$template->id}/preview.ass";
            $disk->put($assRelative, $ass);
            $assPath = $disk->path($assRelative);
        }

        $watermarkPath = null;
        if (! empty($config['branding']['watermark_path'])) {
            $watermarkPath = $disk->path($config['branding']['watermark_path']);
        }
        $watermarkOpacity = (float) ($config['branding']['watermark_opacity'] ?? 0.8);

        $layers = $config['layers'] ?? [];
        if (! empty($config['progress_bar']['enabled']) && ! $this->hasProgressBarLayer($layers)) {
            $layers[] = [
                'id' => 'legacy-progress-bar',
                'type' => 'progress_bar',
                'z_index' => 999,
                'props' => [
                    'color' => $config['progress_bar']['color'] ?? '#7C5CFF',
                    'background_color' => $config['progress_bar']['background_color'] ?? '#000000',
                    'height_px' => $config['progress_bar']['height_px'] ?? 6,
                    'position' => $config['progress_bar']['position'] ?? 'bottom',
                ],
            ];
        }

        $outputRelative = "templates/{$template->id}/preview.mp4";
        $this->ffmpeg->renderClip(
            $sourcePath,
            $disk->path($outputRelative),
            $start,
            $end,
            $targetWidth,
            $targetHeight,
            $keyframes,
            $assPath,
            $watermarkPath,
            $watermarkOpacity,
            $layers,
            fn (string $relative) => $disk->path($relative),
            $config['video_region'] ?? null,
            (string) ($config['canvas_background_color'] ?? '#000000'),
            $effectConfig,
        );

        return $outputRelative;
    }

    /**
     * @param  array<int, array<string, mixed>>  $layers
     */
    private function hasProgressBarLayer(array $layers): bool
    {
        foreach ($layers as $layer) {
            if (($layer['type'] ?? null) === 'progress_bar') {
                return true;
            }
        }

        return false;
    }
}
