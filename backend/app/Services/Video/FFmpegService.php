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
        private readonly LayerCompositionService $layerService = new LayerCompositionService(),
        // Same font-file fallback as LayerCompositionService (see its constructor
        // docblock and config('services.media.default_font_file')) — used by
        // renderCoverSegment()'s own drawtext call, which sits outside the layer
        // pipeline so it needs its own copy of this rather than reaching into
        // $layerService for it.
        private readonly ?string $defaultFontFile = null,
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
     * Detect silent gaps in [start, start+duration] of $sourcePath via ffmpeg's
     * silencedetect filter, parsed off its stderr log (it has no structured output
     * mode). -vn skips video decode entirely since only audio is analyzed here.
     * Never throws — silencedetect's "success" is just reaching EOF, and a source
     * with no silence at all is a completely normal result, not a failure.
     *
     * @return list<array{start: float, end: float}>  clip-relative
     */
    public function detectSilence(
        string $sourcePath,
        float $start,
        float $duration,
        float $noiseDb = -35,
        float $minSilenceSeconds = 0.6
    ): array {
        $result = Process::timeout(300)->run([
            $this->ffmpegBin, '-y',
            '-ss', (string) $start, '-t', (string) $duration, '-i', $sourcePath,
            '-vn', '-af', "silencedetect=noise={$noiseDb}dB:d={$minSilenceSeconds}",
            '-f', 'null', '-',
        ]);

        $intervals = [];
        $pendingStart = null;

        foreach (explode("\n", $result->errorOutput()) as $line) {
            if (preg_match('/silence_start:\s*(-?[\d.]+)/', $line, $m)) {
                $pendingStart = max(0.0, (float) $m[1]);
            } elseif ($pendingStart !== null && preg_match('/silence_end:\s*(-?[\d.]+)/', $line, $m)) {
                $intervals[] = ['start' => $pendingStart, 'end' => min((float) $m[1], $duration)];
                $pendingStart = null;
            }
        }

        // A silence that runs right up to the end of the window never gets its own
        // silence_end line (the input just ends).
        if ($pendingStart !== null && $pendingStart < $duration) {
            $intervals[] = ['start' => $pendingStart, 'end' => $duration];
        }

        return $intervals;
    }

    /**
     * Cut $clipStart-relative $keepIntervals out of $sourcePath and concatenate
     * them into a single silence-free file — a pre-pass ahead of renderClip() (see
     * SilenceTrimmer). Uses the trim/concat filter pair rather than -ss/-t per
     * segment so every kept piece comes from one decoded pass of the source.
     *
     * @param  list<array{start: float, end: float}>  $keepIntervals  clip-relative
     */
    public function extractWithoutSilence(string $sourcePath, string $outPath, float $clipStart, array $keepIntervals): void
    {
        $this->ensureDir($outPath);

        $inputArgs = ['-i', $sourcePath];
        $graph = [];
        $labels = '';

        foreach (array_values($keepIntervals) as $i => $seg) {
            $absStart = $clipStart + $seg['start'];
            $absEnd = $clipStart + $seg['end'];
            $graph[] = "[0:v]trim=start={$absStart}:end={$absEnd},setpts=PTS-STARTPTS[v{$i}]";
            $graph[] = "[0:a]atrim=start={$absStart}:end={$absEnd},asetpts=PTS-STARTPTS[a{$i}]";
            $labels .= "[v{$i}][a{$i}]";
        }

        $n = count($keepIntervals);
        $graph[] = "{$labels}concat=n={$n}:v=1:a=1[vout][aout]";

        $outputArgs = [
            // Re-encoded again by renderClip() right after, so favor quality over
            // size here to limit how much this intermediate pass compounds loss.
            '-c:v', 'libx264', '-preset', $this->x264Preset, '-crf', '18',
            '-c:a', 'aac', '-b:a', '192k',
            $outPath,
        ];

        $this->runWithFilterScript($inputArgs, $graph, ['-map', '[vout]', '-map', '[aout]'], $outputArgs, 'remove silence', 1800);
    }

    /**
     * Render a clip: trim [start,end], apply a (possibly animated) crop to reach
     * the target aspect ratio/resolution, and optionally burn in an ASS subtitle file.
     *
     * @param  array<int, array{time: float, x: float, y: float, width: float, height: float}>  $cropKeyframes  relative to clip start
     * @param  array<int, array<string, mixed>>  $layers  override-merged template layers (text/image/progress_bar/audio/rect), see LayerCompositionService
     * @param  (callable(string): string)|null  $resolveLayerPath  resolves a layer's stored relative path to an absolute path; required if $layers references any image/audio layer
     * @param  ?array{x: float, y: float, width: float, height: float}  $videoRegion  null (default, every template before this feature) fills the whole canvas exactly as before — a smaller region insets the video into that box instead, with $canvasBackgroundColor filling the rest, so template layers (typically a 'rect' + 'text' pair) can build bars around it. Smart-pan crop keyframes and subtitle burn-in are computed against the full canvas either way — see the docblock at the call site inside this method for why.
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
        array $layers = [],
        ?callable $resolveLayerPath = null,
        ?array $videoRegion = null,
        string $canvasBackgroundColor = '#000000',
    ): void {
        $this->ensureDir($outPath);

        $duration = max(0.1, $end - $start);

        [$graph, $videoLabel] = $this->buildCropSegments('0:v', $cropKeyframes, $duration, 'crop');
        $graph[] = "[{$videoLabel}]scale={$targetWidth}:{$targetHeight}[scaled]";
        $videoLabel = 'scaled';

        if ($subtitlesAssPath && file_exists($subtitlesAssPath)) {
            $escaped = $this->escapeFilterPath($subtitlesAssPath);
            $graph[] = "[{$videoLabel}]ass='{$escaped}'[captioned]";
            $videoLabel = 'captioned';
        }

        // Inset the video into a sub-region of the canvas instead of leaving it
        // full-bleed — smart-pan crop and caption burn-in above are deliberately
        // left targeting the FULL canvas (unchanged): the region fit below is a
        // cover-crop (scale increase + crop, same idiom as renderCoverSegment()'s
        // background and buildSplitGraph()'s halves) of that already-correctly-
        // framed, already-captioned result, so captions move/scale with the video
        // through this step rather than needing their own separate repositioning.
        if ($videoRegion) {
            // Merged against full-frame defaults so a partially-specified region
            // in a template's user-editable config (e.g. only {height} given)
            // doesn't throw on a missing array key or silently zero out a
            // dimension — same defensiveness as isFullFrameRegion() below.
            $videoRegion = array_merge(['x' => 0.0, 'y' => 0.0, 'width' => 1.0, 'height' => 1.0], $videoRegion);
        }

        if ($videoRegion && ! $this->isFullFrameRegion($videoRegion)) {
            $regionW = max(2, (int) round($targetWidth * $videoRegion['width']));
            $regionH = max(2, (int) round($targetHeight * $videoRegion['height']));
            $regionX = (int) round($targetWidth * $videoRegion['x']);
            $regionY = (int) round($targetHeight * $videoRegion['y']);
            $bg = $this->normalizeHexColor($canvasBackgroundColor);

            $graph[] = "[{$videoLabel}]scale={$regionW}:{$regionH}:force_original_aspect_ratio=increase,crop={$regionW}:{$regionH}[inset]";
            $graph[] = "color=c={$bg}:s={$targetWidth}x{$targetHeight}:d={$duration}[canvas]";
            $graph[] = "[canvas][inset]overlay={$regionX}:{$regionY}[regioncomposited]";
            $videoLabel = 'regioncomposited';
        }

        $inputArgs = [
            // -ss/-t must sit BEFORE their -i to bind to that input. Once a second
            // -i (the watermark) follows, a trailing -t here would instead bind to
            // THAT input — silently leaving this source clip untrimmed and reading
            // to EOF (observed: output ran to the source's full remaining length).
            '-ss', (string) $start, '-t', (string) $duration, '-i', $sourceVideoPath,
        ];

        // Template layers (text/logo/progress-bar/background-audio) render between
        // the caption burn-in above and the watermark overlay below — captions stay
        // implicitly at the bottom of the stack, watermark implicitly at the top, in
        // this pass (see LayerCompositionService docblock).
        $audioLabels = [];
        $nextInputIndex = 1;
        $layerTempFiles = [];
        if (! empty($layers)) {
            $built = $this->layerService->buildGraph(
                $layers,
                $videoLabel,
                $targetWidth,
                $targetHeight,
                $duration,
                $resolveLayerPath ?? fn (string $p) => $p,
                $nextInputIndex,
            );
            $graph = array_merge($graph, $built['graph']);
            $inputArgs = array_merge($inputArgs, $built['inputArgs']);
            $videoLabel = $built['videoLabel'];
            $audioLabels = $built['audioLabels'];
            $nextInputIndex += $built['inputCount'];
            $layerTempFiles = $built['tempFiles'];
        }

        $outputArgs = [];

        if ($watermarkPath && file_exists($watermarkPath)) {
            $opacity = number_format(max(0, min(1, $watermarkOpacity)), 3, '.', '');
            $watermarkIndex = $nextInputIndex;
            $inputArgs[] = '-i';
            $inputArgs[] = $watermarkPath;
            $graph[] = "[{$watermarkIndex}:v]format=rgba,colorchannelmixer=aa={$opacity}[wm]";
            $graph[] = "[{$videoLabel}][wm]overlay=W-w-24:24[vout]";
            $videoLabel = 'vout';
            $outputArgs[] = '-shortest';
        }

        $audioMapLabel = '0:a?';
        if (! empty($audioLabels)) {
            $mixInputs = array_merge(['[0:a]'], $audioLabels);
            $graph[] = implode('', $mixInputs) . 'amix=inputs=' . count($mixInputs) . ':duration=first:dropout_transition=0[aout]';
            $audioMapLabel = '[aout]';
        }

        $outputArgs = array_merge($outputArgs, [
            '-c:v', 'libx264', '-preset', $this->x264Preset, '-crf', '21',
            '-c:a', 'aac', '-b:a', '128k',
            '-movflags', '+faststart',
            $outPath,
        ]);

        try {
            $this->runWithFilterScript(
                $inputArgs,
                $graph,
                ['-map', "[{$videoLabel}]", '-map', $audioMapLabel],
                $outputArgs,
                'render clip',
                1800
            );
        } finally {
            array_map('unlink', array_filter($layerTempFiles, 'file_exists'));
        }
    }

    /**
     * Render a reaction clip: composite a webcam recording over the source clip
     * (picture-in-picture, or split-screen) instead of renderClip()'s single-video
     * path. Both audio tracks are mixed together (reactor commentary + source clip
     * audio) rather than picking one. See renderClip() for the shared trim/subtitle/
     * watermark conventions this mirrors.
     *
     * @param  array<int, array{time: float, x: float, y: float, width: float, height: float}>  $cropKeyframes  only meaningful for pip_* layouts — split_* layouts fill their half-frame with a plain cover-crop instead (see class docblock on buildSplitGraph())
     * @param  array<int, array<string, mixed>>  $layers  override-merged template layers; RenderClipJob filters out any pip_video layer before calling here since reaction_layout already owns PiP for this clip (see class docblock on LayerCompositionService's pip_video case)
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
        array $layers = [],
        ?callable $resolveLayerPath = null,
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

        $inputArgs = [
            // -ss/-t must each sit immediately BEFORE their own -i — with two-plus
            // inputs, a trailing -t instead binds to the NEXT -i (see renderClip()).
            '-ss', (string) $start, '-t', (string) $duration, '-i', $sourceVideoPath,
            '-t', (string) $duration, '-i', $webcamVideoPath,
        ];

        $audioLabels = [];
        $nextInputIndex = 2;
        $layerTempFiles = [];
        if (! empty($layers)) {
            $built = $this->layerService->buildGraph(
                $layers,
                $outputLabel,
                $targetWidth,
                $targetHeight,
                $duration,
                $resolveLayerPath ?? fn (string $p) => $p,
                $nextInputIndex,
            );
            $graph = array_merge($graph, $built['graph']);
            $inputArgs = array_merge($inputArgs, $built['inputArgs']);
            $outputLabel = $built['videoLabel'];
            $audioLabels = $built['audioLabels'];
            $nextInputIndex += $built['inputCount'];
            $layerTempFiles = $built['tempFiles'];
        }

        if ($watermarkPath && file_exists($watermarkPath)) {
            $opacity = number_format(max(0, min(1, $watermarkOpacity)), 3, '.', '');
            $watermarkIndex = $nextInputIndex;
            $inputArgs[] = '-i';
            $inputArgs[] = $watermarkPath;
            $graph[] = "[{$watermarkIndex}:v]format=rgba,colorchannelmixer=aa={$opacity}[wm]";
            $graph[] = "[{$outputLabel}][wm]overlay=W-w-24:24[final]";
            $outputLabel = 'final';
        }

        // duration=shortest exactly reproduces the pre-layers behavior when there
        // are no template audio layers (both real tracks already share the same -t
        // trim, so shortest/first are equivalent there anyway); duration=first only
        // kicks in once a bg-music layer joins the mix, so a shorter music bed can't
        // truncate the source+webcam audio (see renderClip()'s equivalent case).
        $mixInputs = array_merge(['[0:a]', '[1:a]'], $audioLabels);
        $mixDuration = empty($audioLabels) ? 'shortest' : 'first';
        $graph[] = implode('', $mixInputs) . 'amix=inputs=' . count($mixInputs) . ":duration={$mixDuration}:dropout_transition=0[aout]";

        $outputArgs = [
            // Both real video/audio inputs already share the same -t, but a watermark
            // PNG's single-frame stream has no real duration of its own and can
            // otherwise stretch the mux past the trimmed length (see renderClip()).
            '-shortest',
            '-c:v', 'libx264', '-preset', $this->x264Preset, '-crf', '21',
            '-c:a', 'aac', '-b:a', '128k',
            '-movflags', '+faststart',
            $outPath,
        ];

        try {
            $this->runWithFilterScript(
                $inputArgs,
                $graph,
                ['-map', "[{$outputLabel}]", '-map', '[aout]'],
                $outputArgs,
                'render reaction clip',
                1800
            );
        } finally {
            array_map('unlink', array_filter($layerTempFiles, 'file_exists'));
        }
    }

    /**
     * Render a still-image "cover" segment: a looped image for $duration, optional
     * narration audio (falls back to a silent track so every segment concatSegments()
     * joins has a real audio stream), optional centered drawtext overlay. Used for
     * both the AI reaction intro (image + TTS narration + reaction_script text) and
     * the static outro card (image + no audio + short caption) — see RenderClipJob.
     */
    public function renderCoverSegment(
        string $imagePath,
        ?string $audioPath,
        string $outPath,
        int $targetWidth,
        int $targetHeight,
        float $duration,
        ?string $overlayText = null,
    ): void {
        $this->ensureDir($outPath);
        $duration = max(0.5, $duration);

        $inputArgs = ['-loop', '1', '-t', (string) $duration, '-i', $imagePath];
        if ($audioPath && file_exists($audioPath)) {
            $inputArgs = array_merge($inputArgs, ['-t', (string) $duration, '-i', $audioPath]);
        } else {
            $inputArgs = array_merge($inputArgs, ['-f', 'lavfi', '-t', (string) $duration, '-i', 'anullsrc=r=44100:cl=stereo']);
        }

        $graph = [];
        $videoLabel = 'cover';
        $graph[] = "[0:v]scale={$targetWidth}:{$targetHeight}:force_original_aspect_ratio=increase,crop={$targetWidth}:{$targetHeight}[{$videoLabel}]";

        $textTempFile = null;
        if ($overlayText !== null && trim($overlayText) !== '') {
            // textfile= (raw content, verbatim) instead of text='...' (escaped,
            // inline) — see LayerCompositionService::buildTextLayer()'s docblock for
            // why: drawtext's own escaping for a literal ' reliably segfaults this
            // ffmpeg build once combined with another escaped character (colon,
            // percent) in the same value, which AI-generated reaction lines hit
            // constantly (contractions/quotes alongside times or punctuation).
            $wrapped = wordwrap(trim($overlayText), 28, "\n", true);
            $textTempFile = tempnam(sys_get_temp_dir(), 'covertext_') . '.txt';
            file_put_contents($textTempFile, $wrapped);

            $fontSize = max(1, (int) round($targetWidth * 0.06));
            $params = [
                "textfile='" . $this->escapeFilterPath($textTempFile) . "'",
                'expansion=none',
                "fontsize={$fontSize}",
                'fontcolor=white',
                'x=(main_w-text_w)/2',
                'y=(main_h-text_h)/2',
                'line_spacing=8',
                'box=1',
                'boxcolor=black@0.55',
                'boxborderw=16',
            ];
            // Bare font= (fontconfig name lookup) needs a working fontconfig on the
            // ffmpeg host, which isn't a safe cross-machine assumption — see
            // config('services.media.default_font_file')'s docblock. Only reached
            // when no default_font_file is configured at all.
            if ($this->defaultFontFile) {
                $params[] = "fontfile='" . $this->escapeFilterPath($this->defaultFontFile) . "'";
            }
            $graph[] = "[{$videoLabel}]drawtext=" . implode(':', $params) . '[covertext]';
            $videoLabel = 'covertext';
        }

        $outputArgs = [
            '-c:v', 'libx264', '-preset', $this->x264Preset, '-crf', '21',
            '-c:a', 'aac', '-b:a', '128k',
            '-pix_fmt', 'yuv420p',
            '-shortest',
            $outPath,
        ];

        try {
            $this->runWithFilterScript(
                $inputArgs,
                $graph,
                ['-map', "[{$videoLabel}]", '-map', '1:a'],
                $outputArgs,
                'render cover segment',
                300
            );
        } finally {
            if ($textTempFile && file_exists($textTempFile)) {
                unlink($textTempFile);
            }
        }
    }

    /**
     * Concatenate several already-rendered segments (cover intro/outro + the main
     * clip output, in order) into one file. Uses the concat FILTER (re-decode +
     * re-encode), not the concat demuxer's "-c copy" — a cover segment and the main
     * clip can differ slightly in fps/codec profile even though both come out of
     * this same service, and the filter tolerates that where the demuxer would
     * simply refuse to join them. Each input is defensively re-scaled to the target
     * resolution for the same reason. See RenderClipJob for how this is used.
     *
     * @param  list<string>  $segmentPaths  in playback order
     */
    public function concatSegments(array $segmentPaths, string $outPath, int $targetWidth, int $targetHeight): void
    {
        $this->ensureDir($outPath);
        $segmentPaths = array_values($segmentPaths);
        $n = count($segmentPaths);

        if ($n === 0) {
            throw new InvalidArgumentException('concatSegments() requires at least one segment.');
        }

        if ($n === 1) {
            copy($segmentPaths[0], $outPath);

            return;
        }

        $inputArgs = [];
        $graph = [];
        $pairLabels = '';

        foreach ($segmentPaths as $i => $path) {
            $inputArgs[] = '-i';
            $inputArgs[] = $path;
            $graph[] = "[{$i}:v]scale={$targetWidth}:{$targetHeight}:force_original_aspect_ratio=increase,crop={$targetWidth}:{$targetHeight},setsar=1[v{$i}]";
            $pairLabels .= "[v{$i}][{$i}:a]";
        }

        $graph[] = "{$pairLabels}concat=n={$n}:v=1:a=1[vout][aout]";

        $outputArgs = [
            '-c:v', 'libx264', '-preset', $this->x264Preset, '-crf', '21',
            '-c:a', 'aac', '-b:a', '128k',
            '-movflags', '+faststart',
            $outPath,
        ];

        $this->runWithFilterScript($inputArgs, $graph, ['-map', '[vout]', '-map', '[aout]'], $outputArgs, 'concat segments', 1800);
    }

    /**
     * Picture-in-picture: source clip fills the whole frame (same smart-crop path as
     * renderClip()), webcam is center-cropped to a square and pinned to a corner.
     *
     * @return array{0: string[], 1: string}
     */
    private function buildPipGraph(array $cropKeyframes, float $duration, int $targetWidth, int $targetHeight, bool $right, bool $bottom): array
    {
        [$graph, $videoLabel] = $this->buildCropSegments('0:v', $cropKeyframes, $duration, 'crop');
        $graph[] = "[{$videoLabel}]scale={$targetWidth}:{$targetHeight}[base]";

        $pipSize = (int) round($targetWidth * self::PIP_SIZE_RATIO);
        $x = $right ? 'W-w-' . self::PIP_MARGIN : (string) self::PIP_MARGIN;
        $y = $bottom ? 'H-h-' . self::PIP_MARGIN : (string) self::PIP_MARGIN;

        $graph[] = "[1:v]scale={$pipSize}:{$pipSize}:force_original_aspect_ratio=increase,crop={$pipSize}:{$pipSize}[pip]";
        $graph[] = "[base][pip]overlay={$x}:{$y}[composited]";

        return [$graph, 'composited'];
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
     * Collapses any keyframes that land on the same (or an out-of-order) instant —
     * silence-removal remapping (see SilenceTrimmer) can in principle produce this
     * even though callers are expected to have already deduped — keeping the first.
     * buildCropSegments() needs strictly increasing times since each interval's
     * span becomes a trim() filter's start/end.
     *
     * @param  array<int, array{time: float, x: float, y: float, width: float, height: float}>  $cropKeyframes
     * @return array<int, array{time: float, x: float, y: float, width: float, height: float}>
     */
    private function dedupeCropKeyframes(array $cropKeyframes): array
    {
        $deduped = [];
        $lastTime = null;

        foreach ($cropKeyframes as $k) {
            if ($lastTime !== null && (float) $k['time'] <= $lastTime) {
                continue;
            }
            $deduped[] = $k;
            $lastTime = (float) $k['time'];
        }

        return $deduped;
    }

    /**
     * Build a filtergraph that pans the crop window across $cropKeyframes without
     * ever evaluating one large per-frame expression against the whole clip. An
     * earlier version built a single dynamic crop=... expression covering every
     * keyframe — first as a nested if() chain, then (after that hit ffmpeg's eval
     * parser recursion limit on a ~107s clip: "Missing ')' or too many args") as a
     * flat sum of indicator*value terms. The flat sum still failed on the same
     * clip, just later (config/eval time instead of parse time) — a left-
     * associative chain of N terms builds an expression tree of depth O(N)
     * regardless of whether the top-level operator is nested if()s or +, so it hits
     * the same underlying evaluator stack limit either way. The only structural fix
     * is to stop building one expression that scales with keyframe count at all:
     * each interval is cut out with trim+setpts, cropped with its own trivial
     * two-point lerp (O(1) regardless of total keyframe count, no branching or
     * commas needed since trim already isolated exactly this interval), and the
     * pieces are stitched back together with concat. Scales to any clip length or
     * keyframe density.
     *
     * @param  string  $inputLabel  pad to crop, without brackets (e.g. '0:v')
     * @param  array<int, array{time: float, x: float, y: float, width: float, height: float}>  $cropKeyframes  clip-relative
     * @param  string  $labelPrefix  unique per call site sharing a filtergraph, so pad names never collide (e.g. 'crop' vs 'pipcrop')
     * @return array{0: string[], 1: string}  [graph lines, output pad label (no brackets)]
     */
    private function buildCropSegments(string $inputLabel, array $cropKeyframes, float $duration, string $labelPrefix): array
    {
        $keyframes = $this->dedupeCropKeyframes($cropKeyframes);
        $outLabel = "{$labelPrefix}out";

        if (empty($keyframes)) {
            return [["[{$inputLabel}]crop=in_w:in_h[{$outLabel}]"], $outLabel];
        }

        if (count($keyframes) === 1) {
            $k = $keyframes[0];
            $expr = sprintf('crop=%d:%d:%d:%d', (int) $k['width'], (int) $k['height'], (int) $k['x'], (int) $k['y']);

            return [["[{$inputLabel}]{$expr}[{$outLabel}]"], $outLabel];
        }

        $w = (int) $keyframes[0]['width'];
        $h = (int) $keyframes[0]['height'];
        $graph = [];
        $segLabels = [];
        $n = count($keyframes);

        for ($i = 1; $i < $n; $i++) {
            $t0 = $keyframes[$i - 1]['time'];
            $t1 = $keyframes[$i]['time'];
            $x0 = $keyframes[$i - 1]['x'];
            $x1 = $keyframes[$i]['x'];
            $y0 = $keyframes[$i - 1]['y'];
            $y1 = $keyframes[$i]['y'];
            // trim+setpts resets this segment's own timeline to start at 0, so "t"
            // here already equals (original_t - t0) — a plain two-point lerp, no
            // branching needed since trim already picked out exactly this interval.
            $xExpr = "({$x0}+({$x1}-{$x0})*t/({$t1}-{$t0}))";
            $yExpr = "({$y0}+({$y1}-{$y0})*t/({$t1}-{$t0}))";
            $label = "{$labelPrefix}seg{$i}";
            $graph[] = "[{$inputLabel}]trim=start={$t0}:end={$t1},setpts=PTS-STARTPTS,crop={$w}:{$h}:{$xExpr}:{$yExpr}[{$label}]";
            $segLabels[] = "[{$label}]";
        }

        // Holds the final keyframe's position for anything after the last interval.
        $lastTime = (float) end($keyframes)['time'];
        if ($lastTime < $duration - 0.001) {
            $lastX = (int) end($keyframes)['x'];
            $lastY = (int) end($keyframes)['y'];
            $label = "{$labelPrefix}segtail";
            $graph[] = "[{$inputLabel}]trim=start={$lastTime}:end={$duration},setpts=PTS-STARTPTS,crop={$w}:{$h}:{$lastX}:{$lastY}[{$label}]";
            $segLabels[] = "[{$label}]";
        }

        $graph[] = implode('', $segLabels) . 'concat=n=' . count($segLabels) . ":v=1:a=0[{$outLabel}]";

        return [$graph, $outLabel];
    }

    private function escapeFilterPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return str_replace(':', '\\:', $path);
    }

    /**
     * True when $region covers the entire canvas — i.e. functionally identical to
     * no region at all. renderClip() skips the inset-compositing pass entirely in
     * that case rather than doing a needless scale+crop+overlay round-trip.
     *
     * @param  array{x: float, y: float, width: float, height: float}  $region
     */
    private function isFullFrameRegion(array $region): bool
    {
        $eps = 0.001;

        return abs(($region['x'] ?? 0.0)) < $eps
            && abs(($region['y'] ?? 0.0)) < $eps
            && abs(($region['width'] ?? 1.0) - 1.0) < $eps
            && abs(($region['height'] ?? 1.0) - 1.0) < $eps;
    }

    /**
     * Same #RRGGBB validation as LayerCompositionService::normalizeColor() (kept
     * as its own copy rather than shared — see escapeDrawtext()'s equivalent note
     * on that class) — the canvas background color reaches here from a template's
     * user-editable config, unquoted in the filter argument, so this is the
     * injection guard for that field.
     */
    private function normalizeHexColor(string $color, string $default = '#000000'): string
    {
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return $default;
        }

        return $color;
    }

    /**
     * Pick the option this ffmpeg build uses to read a filtergraph from a file.
     *
     * -filter_complex_script was deprecated in ffmpeg 7.0 and *removed* in 8.0, where
     * passing it aborts with "Unrecognized option 'filter_complex_script'" before any
     * work starts. Its replacement, the generic "read this option's value from a file"
     * prefix -/filter_complex, landed in 6.1. Builds differ per machine, so resolve
     * once per process from the reported version rather than assuming either one.
     */
    private function filterScriptOption(): string
    {
        static $option = null;

        if ($option !== null) {
            return $option;
        }

        $major = 0;
        $result = Process::timeout(15)->run([$this->ffmpegBin, '-hide_banner', '-version']);
        if ($result->successful() && preg_match('/ffmpeg version n?(\d+)/i', $result->output(), $m)) {
            $major = (int) $m[1];
        }

        // Unparseable version (custom build string, git snapshot): assume a recent
        // ffmpeg, since -/filter_complex is the form that survives going forward.
        return $option = ($major === 0 || $major >= 7) ? '-/filter_complex' : '-filter_complex_script';
    }

    /**
     * Run ffmpeg with a filtergraph passed in a temp file (see filterScriptOption())
     * instead of inline on the command line. A dynamic smart-crop expression grows
     * with the clip's keyframe count and can reach several KB — well past the ~8191
     * character line-length limit cmd.exe silently enforces on Windows, where
     * Symfony's Process component routes array-form commands through cmd.exe. That
     * failure mode is silent (non-zero exit, empty stdout/stderr) because the shell
     * never launches ffmpeg at all, so keeping filtergraphs off the command line
     * sidesteps the limit entirely rather than relying on expressions staying short.
     */
    private function runWithFilterScript(array $inputArgs, array $graphLines, array $mapArgs, array $outputArgs, string $action, int $timeout): void
    {
        $scriptPath = tempnam(sys_get_temp_dir(), 'ffgraph_');
        file_put_contents($scriptPath, implode(";\n", $graphLines));

        try {
            $args = [
                $this->ffmpegBin, '-y',
                ...$inputArgs,
                $this->filterScriptOption(), $scriptPath,
                ...$mapArgs,
                ...$outputArgs,
            ];

            $result = Process::timeout($timeout)->run($args);
            $this->assertSuccess($result, $action);
        } finally {
            @unlink($scriptPath);
        }
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
