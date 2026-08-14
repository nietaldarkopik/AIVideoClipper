<?php

namespace App\Services\Video;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class FFmpegService
{
    public function __construct(
        private readonly string $ffmpegBin = 'ffmpeg',
        private readonly string $ffprobeBin = 'ffprobe',
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
            '-ss', (string) $start, '-i', $sourceVideoPath,
            '-t', (string) $duration,
        ];

        if ($watermarkPath && file_exists($watermarkPath)) {
            $args = array_merge($args, ['-i', $watermarkPath]);
            $args = array_merge($args, [
                '-filter_complex',
                "[0:v]{$videoFilter}[base];[base][1:v]overlay=W-w-24:24",
            ]);
        } else {
            $args = array_merge($args, ['-vf', $videoFilter]);
        }

        $args = array_merge($args, [
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21',
            '-c:a', 'aac', '-b:a', '128k',
            '-movflags', '+faststart',
            $outPath,
        ]);

        $result = Process::timeout(1800)->run($args);
        $this->assertSuccess($result, 'render clip');
    }

    /**
     * Build a per-frame ffmpeg crop expression from crop keyframes. With zero or one
     * keyframe this is a static crop; with multiple it step-interpolates between them
     * (ffmpeg expressions don't have an easy native lerp across arbitrary keyframes,
     * so we approximate with a nearest-keyframe step function using `if()` chains).
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
        $xExpr = str_replace(',', '\\,', $this->stepExpression($cropKeyframes, 'x'));
        $yExpr = str_replace(',', '\\,', $this->stepExpression($cropKeyframes, 'y'));

        return "crop={$w}:{$h}:{$xExpr}:{$yExpr}";
    }

    private function stepExpression(array $keyframes, string $field): string
    {
        // Builds: if(lt(t,k1.time),k0.value, if(lt(t,k2.time),k1.value, ... lastValue))
        $expr = (string) (int) end($keyframes)[$field];
        for ($i = count($keyframes) - 1; $i > 0; $i--) {
            $threshold = $keyframes[$i]['time'];
            $value = (int) $keyframes[$i - 1][$field];
            $expr = "if(lt(t,{$threshold}),{$value},{$expr})";
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
