<?php

namespace App\Services\Video;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

class FFmpegService
{
    // Reaction-mode picture-in-picture box: square, this fraction of target width,
    // pinned to a corner with the same margin renderClip() uses for the watermark.
    private const PIP_SIZE_RATIO = 0.30;

    private const PIP_MARGIN = 24;

    public function __construct(
        private readonly string $ffmpegBin = 'ffmpeg',
        private readonly string $ffprobeBin = 'ffprobe',
        // x264 preset for renderClip(). Quality is set by -crf below, not by preset —
        // preset only trades CPU time for compression *efficiency* (output file
        // size), so dropping to a faster preset on a CPU-only, no-GPU machine cuts
        // encode time/load without touching visual quality, just a somewhat larger
        // output file. See config('services.media.ffmpeg_preset').
        private readonly string $x264Preset = 'superfast',
    ) {
    }

    /**
     * Probe a media file for duration/width/height/size via ffprobe.
     *
     * @return array{duration: float|null, width: int|null, height: int|null, size: int|null}
     */
    public function probe(string $path): array
    {
        $result = Process::run([
            $this->ffprobeBin, '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height:format=duration,size',
            '-of', 'json',
            $path,
        ]);

        if (! $result->successful()) {
            Log::warning('ffprobe failed', ['path' => $path, 'error' => $result->errorOutput()]);

            return ['duration' => null, 'width' => null, 'height' => null, 'size' => file_exists($path) ? filesize($path) : null];
        }

        $data = json_decode($result->output(), true) ?: [];
        $stream = $data['streams'][0] ?? [];
        $format = $data['format'] ?? [];

        return [
            'duration' => isset($format['duration']) ? (float) $format['duration'] : null,
            'width' => isset($stream['width']) ? (int) $stream['width'] : null,
            'height' => isset($stream['height']) ? (int) $stream['height'] : null,
            'size' => isset($format['size']) ? (int) $format['size'] : (file_exists($path) ? filesize($path) : null),
        ];
    }

    public function probeDuration(string $path): ?float
    {
        $result = Process::run([
            $this->ffprobeBin, '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);

        if (! $result->successful()) {
            return null;
        }

        $value = trim($result->output());

        return is_numeric($value) ? (float) $value : null;
    }

    public function extractAudio(string $videoPath, string $audioOutPath): void
    {
        $this->ensureDir($audioOutPath);

        $result = Process::timeout(600)->run([
            $this->ffmpegBin, '-y', '-i', $videoPath,
            '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le',
            $audioOutPath,
        ]);

        $this->assertSuccess($result, 'extract audio');
    }

    /**
     * Cut + compress a slice of an audio file to small MP3 chunks, for uploading to
     * transcription APIs that cap request size (e.g. OpenAI's 25MB limit). Using
     * -ss before -i is a fast (if slightly imprecise) seek, which is fine here since
     * caption timing tolerates a few hundred ms of drift.
     */
    public function transcodeAudioSegment(string $sourcePath, string $outPath, float $start, float $duration, int $bitrateKbps = 48): void
    {
        $this->ensureDir($outPath);

        $result = Process::timeout(300)->run([
            $this->ffmpegBin, '-y',
            '-ss', (string) $start, '-i', $sourcePath, '-t', (string) $duration,
            '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'libmp3lame', '-b:a', $bitrateKbps . 'k',
            $outPath,
        ]);

        $this->assertSuccess($result, 'transcode audio segment');
    }

    /**
     * Cut a slice of an already-extracted WAV to its own small WAV file, for feeding
     * a local transcription engine (e.g. whisper-engine) a bounded amount of audio
     * per request instead of an entire long recording at once — keeps peak CPU/RAM
     * per request low enough that a long video won't stall or crash the machine.
     */
    public function sliceAudioSegment(string $sourcePath, string $outPath, float $start, float $duration): void
    {
        $this->ensureDir($outPath);

        $result = Process::timeout(300)->run([
            $this->ffmpegBin, '-y',
            '-ss', (string) $start, '-i', $sourcePath, '-t', (string) $duration,
            '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le',
            $outPath,
        ]);

        $this->assertSuccess($result, 'slice audio segment');
    }

    public function generateThumbnail(string $videoPath, string $outPath, float $atSecond = 1.0): void
    {
        $this->ensureDir($outPath);

        $result = Process::timeout(120)->run([
            $this->ffmpegBin, '-y', '-ss', (string) $atSecond, '-i', $videoPath,
            '-frames:v', '1', '-q:v', '3', $outPath,
        ]);

        $this->assertSuccess($result, 'generate thumbnail');
    }

    /**
     * Render a clip: trim [start,end], apply a (possibly animated) crop to reach
     * the target aspect ratio/resolution, and optionally burn in an ASS subtitle file.
     *
     * @param  array<int, array{time: float, x: float, y: float, width: float, height: float}>  $cropKeyframes  relative to clip start
     */
    public function renderClip(
        string $sourceVideoPath,
        string $outPath,
        float $start,
        float $end,
        int $targetWidth,
        int $targetHeight,
        array $cropKeyframes = [],
        ?string $subtitlesAssPath = null,
        ?string $watermarkPath = null,
        float $watermarkOpacity = 0.8,
    ): void {
        $this->ensureDir($outPath);

        $duration = max(0.1, $end - $start);
        $filters = [];

        $filters[] = $this->buildCropExpression($cropKeyframes, $duration);
        $filters[] = "scale={$targetWidth}:{$targetHeight}";

        if ($subtitlesAssPath && file_exists($subtitlesAssPath)) {
            $escaped = $this->escapeFilterPath($subtitlesAssPath);
            $filters[] = "ass='{$escaped}'";
        }

        $videoFilter = implode(',', array_filter($filters));

        $args = [
            $this->ffmpegBin, '-y',
            // -ss/-t must sit BEFORE their -i to bind to that input. Once a second
            // -i (the watermark) follows, a trailing -t here would instead bind to
            // THAT input — silently leaving this source clip untrimmed and reading
            // to EOF (observed: output ran to the source's full remaining length).
            '-ss', (string) $start, '-t', (string) $duration, '-i', $sourceVideoPath,
        ];

        if ($watermarkPath && file_exists($watermarkPath)) {
            $opacity = number_format(max(0, min(1, $watermarkOpacity)), 3, '.', '');
            $args = array_merge($args, ['-i', $watermarkPath]);
            $args = array_merge($args, [
                '-filter_complex',
                "[1:v]format=rgba,colorchannelmixer=aa={$opacity}[wm];[0:v]{$videoFilter}[base];[base][wm]overlay=W-w-24:24",
                '-shortest',
            ]);
        } else {
            $args = array_merge($args, ['-vf', $videoFilter]);
        }

        $args = array_merge($args, [
            '-c:v', 'libx264', '-preset', $this->x264Preset, '-crf', '21',
            '-c:a', 'aac', '-b:a', '128k',
            '-movflags', '+faststart',
            $outPath,
        ]);

        $result = Process::timeout(1800)->run($args);
        $this->assertSuccess($result, 'render clip');
    }

    /**
     * Render a reaction clip: composite a webcam recording over the source clip
     * (picture-in-picture, or split-screen) instead of renderClip()'s single-video
     * path. Both audio tracks are mixed together (reactor commentary + source clip
     * audio) rather than picking one. See renderClip() for the shared trim/subtitle/
     * watermark conventions this mirrors.
     *
     * @param  array<int, array{time: float, x: float, y: float, width: float, height: float}>  $cropKeyframes  only meaningful for pip_* layouts — split_* layouts fill their half-frame with a plain cover-crop instead (see class docblock on buildSplitGraph())
     */
    public function renderReactionClip(
        string $sourceVideoPath,
        string $webcamVideoPath,
        string $outPath,
        float $start,
        float $end,
        int $targetWidth,
        int $targetHeight,
        string $layout,
        array $cropKeyframes = [],
        ?string $subtitlesAssPath = null,
        ?string $watermarkPath = null,
        float $watermarkOpacity = 0.8,
    ): void {
        $this->ensureDir($outPath);
        $duration = max(0.1, $end - $start);

        [$graph, $outputLabel] = match ($layout) {
            'pip_bottom_right' => $this->buildPipGraph($cropKeyframes, $duration, $targetWidth, $targetHeight, right: true, bottom: true),
            'pip_bottom_left' => $this->buildPipGraph($cropKeyframes, $duration, $targetWidth, $targetHeight, right: false, bottom: true),
            'split_top_bottom' => $this->buildSplitGraph($targetWidth, $targetHeight, vertical: true),
            'split_side_by_side' => $this->buildSplitGraph($targetWidth, $targetHeight, vertical: false),
            default => throw new InvalidArgumentException("Unknown reaction layout [{$layout}]."),
        };

        if ($subtitlesAssPath && file_exists($subtitlesAssPath)) {
            $escaped = $this->escapeFilterPath($subtitlesAssPath);
            $graph[] = "[{$outputLabel}]ass='{$escaped}'[captioned]";
            $outputLabel = 'captioned';
        }

        $args = [
            $this->ffmpegBin, '-y',
            // -ss/-t must each sit immediately BEFORE their own -i — with two-plus
            // inputs, a trailing -t instead binds to the NEXT -i (see renderClip()).
            '-ss', (string) $start, '-t', (string) $duration, '-i', $sourceVideoPath,
            '-t', (string) $duration, '-i', $webcamVideoPath,
        ];

        if ($watermarkPath && file_exists($watermarkPath)) {
            $opacity = number_format(max(0, min(1, $watermarkOpacity)), 3, '.', '');
            $args = array_merge($args, ['-i', $watermarkPath]);
            $graph[] = "[2:v]format=rgba,colorchannelmixer=aa={$opacity}[wm]";
            $graph[] = "[{$outputLabel}][wm]overlay=W-w-24:24[final]";
            $outputLabel = 'final';
        }

        $graph[] = '[0:a][1:a]amix=inputs=2:duration=shortest:dropout_transition=0[aout]';

        $args = array_merge($args, [
            '-filter_complex', implode(';', $graph),
            '-map', "[{$outputLabel}]",
            '-map', '[aout]',
            // Both real video/audio inputs already share the same -t, but a watermark
            // PNG's single-frame stream has no real duration of its own and can
            // otherwise stretch the mux past the trimmed length (see renderClip()).
            '-shortest',
            '-c:v', 'libx264', '-preset', $this->x264Preset, '-crf', '21',
            '-c:a', 'aac', '-b:a', '128k',
            '-movflags', '+faststart',
            $outPath,
        ]);

        $result = Process::timeout(1800)->run($args);
        $this->assertSuccess($result, 'render reaction clip');
    }

    /**
     * Picture-in-picture: source clip fills the whole frame (same smart-crop path as
     * renderClip()), webcam is center-cropped to a square and pinned to a corner.
     *
     * @return array{0: string[], 1: string}
     */
    private function buildPipGraph(array $cropKeyframes, float $duration, int $targetWidth, int $targetHeight, bool $right, bool $bottom): array
    {
        $baseFilter = $this->buildCropExpression($cropKeyframes, $duration) . ",scale={$targetWidth}:{$targetHeight}";
        $pipSize = (int) round($targetWidth * self::PIP_SIZE_RATIO);
        $x = $right ? 'W-w-' . self::PIP_MARGIN : (string) self::PIP_MARGIN;
        $y = $bottom ? 'H-h-' . self::PIP_MARGIN : (string) self::PIP_MARGIN;

        return [
            [
                "[0:v]{$baseFilter}[base]",
                "[1:v]scale={$pipSize}:{$pipSize}:force_original_aspect_ratio=increase,crop={$pipSize}:{$pipSize}[pip]",
                "[base][pip]overlay={$x}:{$y}[composited]",
            ],
            'composited',
        ];
    }

    /**
     * Split-screen: source clip and webcam each fill half the frame. Neither side
     * uses the AI smart-pan crop here — ReframingProvider::detectCropKeyframes()
     * only understands the three whole-frame aspect ratios ('9:16'/'1:1'/'16:9'), not
     * an arbitrary half-frame region, so both halves get a plain "scale to cover,
     * then center-crop" fill instead (the standard ffmpeg object-fit:cover idiom) —
     * good enough for a split layout and needs no source-dimension probing.
     *
     * @return array{0: string[], 1: string}
     */
    private function buildSplitGraph(int $targetWidth, int $targetHeight, bool $vertical): array
    {
        [$regionWidth, $regionHeight] = $vertical
            ? [$targetWidth, intdiv($targetHeight, 2)]
            : [intdiv($targetWidth, 2), $targetHeight];

        $cover = "scale={$regionWidth}:{$regionHeight}:force_original_aspect_ratio=increase,crop={$regionWidth}:{$regionHeight}";
        $stack = $vertical ? 'vstack' : 'hstack';
        // Vertical: webcam on top, source clip on bottom. Horizontal: source clip on
        // the left, webcam on the right.
        $order = $vertical ? '[reaction][base]' : '[base][reaction]';

        return [
            [
                "[0:v]{$cover}[base]",
                "[1:v]{$cover}[reaction]",
                "{$order}{$stack}[composited]",
            ],
            'composited',
        ];
    }

    /**
     * Build a per-frame ffmpeg crop expression from crop keyframes. With zero or one
     * keyframe this is a static crop; with multiple, x/y linearly interpolate between
     * consecutive keyframes (piecewise lerp via an `if()` chain) so the crop pans
     * smoothly across each keyframe interval instead of jump-cutting at each one, and
     * holds the final position after the last keyframe.
     */
    private function buildCropExpression(array $cropKeyframes, float $duration): string
    {
        if (empty($cropKeyframes)) {
            return 'crop=in_w:in_h';
        }

        if (count($cropKeyframes) === 1) {
            $k = $cropKeyframes[0];

            return sprintf('crop=%d:%d:%d:%d', (int) $k['width'], (int) $k['height'], (int) $k['x'], (int) $k['y']);
        }

        $w = (int) $cropKeyframes[0]['width'];
        $h = (int) $cropKeyframes[0]['height'];

        // Commas inside the if(...) eval expressions must be escaped: ffmpeg's
        // filtergraph tokenizer splits on unescaped commas to separate chained
        // filters, even when they appear inside a function call's argument list.
        $xExpr = str_replace(',', '\\,', $this->lerpExpression($cropKeyframes, 'x'));
        $yExpr = str_replace(',', '\\,', $this->lerpExpression($cropKeyframes, 'y'));

        return "crop={$w}:{$h}:{$xExpr}:{$yExpr}";
    }

    private function lerpExpression(array $keyframes, string $field): string
    {
        // Builds nested ifs, one per keyframe interval:
        //   if(lt(t,k1.time), lerp(k0,k1,t), if(lt(t,k2.time), lerp(k1,k2,t), ... kLast.value))
        // Each interval linearly interpolates between its two keyframes' values, so
        // the crop pans smoothly instead of snapping at each keyframe. Past the final
        // keyframe the position just holds. Keyframe times are strictly increasing
        // (each one only exists because the tracked position changed), so every
        // interval's time span is > 0 and the division below is always safe.
        $expr = (string) end($keyframes)[$field];
        for ($i = count($keyframes) - 1; $i > 0; $i--) {
            $t0 = $keyframes[$i - 1]['time'];
            $t1 = $keyframes[$i]['time'];
            $v0 = $keyframes[$i - 1][$field];
            $v1 = $keyframes[$i][$field];
            $lerp = "({$v0}+({$v1}-{$v0})*(t-{$t0})/({$t1}-{$t0}))";
            $expr = "if(lt(t,{$t1}),{$lerp},{$expr})";
        }

        return $expr;
    }

    private function escapeFilterPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return str_replace(':', '\\:', $path);
    }

    private function ensureDir(string $filePath): void
    {
        $dir = dirname($filePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    private function assertSuccess($result, string $action): void
    {
        if (! $result->successful()) {
            throw new RuntimeException("ffmpeg failed to {$action}: " . $result->errorOutput());
        }
    }
}
