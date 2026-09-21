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

    // Frame rate concatSegments() normalizes every segment's video pad to when
    // stitching with a fade transition, so xfade's "inputs must share a time
    // base" requirement is always satisfied regardless of where each segment
    // came from (looped-image cover card vs. re-encoded clip) — see the fps=
    // filter added there. Not used for the plain 'cut' path, which has no such
    // requirement.
    private const FADE_CONCAT_FPS = 30;

    // Similar idea to FADE_CONCAT_FPS but for audio, and NOT limited to the fade
    // path: every segment's audio pad concatSegments() joins — 'cut' or 'fade'
    // — gets resampled to this rate (and forced to stereo) first, so a lower-
    // quality segment (e.g. 16kHz mono TTS narration on an intro card) can't
    // drag the whole chained output down to its format — see the aformat=
    // filter added there. 44.1kHz matches what renderClip()/renderCoverSegment()
    // already output for a real source clip.
    private const CONCAT_AUDIO_SAMPLE_RATE = 44100;

    // Public — VideoResource exposes this alongside thumbnail_strip_url so the
    // frontend timeline's per-pixel slice math (tile index = floor(fraction *
    // tileCount)) never has to hardcode or guess a number that only this class
    // actually controls. Fixed regardless of source duration — see
    // generateThumbnailStrip()'s docblock for why.
    public const THUMBNAIL_STRIP_TILE_COUNT = 100;

    public const THUMBNAIL_STRIP_TILE_HEIGHT = 96;

    public function __construct(
        private readonly string $ffmpegBin = 'ffmpeg',
        private readonly string $ffprobeBin = 'ffprobe',
        // x264 preset for renderClip(). Quality is set by -crf below, not by preset —
        // preset only trades CPU time for compression *efficiency* (output file
        // size), so dropping to a faster preset on a CPU-only, no-GPU machine cuts
        // encode time/load without touching visual quality, just a somewhat larger
        // output file. See config('services.media.ffmpeg_preset').
        private readonly string $x264Preset = 'superfast',
        private readonly LayerCompositionService $layerService = new LayerCompositionService,
        // Same font-file fallback as LayerCompositionService (see its constructor
        // docblock and config('services.media.default_font_file')) — used by
        // renderCoverSegment()'s own drawtext call, which sits outside the layer
        // pipeline so it needs its own copy of this rather than reaching into
        // $layerService for it.
        private readonly ?string $defaultFontFile = null,
    ) {}

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
            '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'libmp3lame', '-b:a', $bitrateKbps.'k',
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
     * Composites a vertical (Shorts) cover into a 16:9 landscape still — a
     * blurred, brightness-darkened copy of the SAME image stretched to fill the
     * full canvas as a background, with the original centered on top at full
     * height. Exists because YouTube's `thumbnails.set` endpoint only ever
     * generates the classic 16:9 derivative sizes (see YouTubeProvider) — a raw
     * portrait upload gets pillarboxed by YouTube itself with a plain
     * blurred-frame background; this produces the same look deliberately from
     * OUR source cover instead, so the padding actually matches the cover's own
     * branding/colors rather than an arbitrary video frame.
     */
    public function renderPillarboxedLandscapeThumbnail(
        string $imagePath,
        string $outPath,
        int $targetWidth = 1280,
        int $targetHeight = 720,
    ): void {
        $this->ensureDir($outPath);

        $filter = "[0:v]split=2[bg][fg];".
            "[bg]scale={$targetWidth}:{$targetHeight}:force_original_aspect_ratio=increase,".
            "crop={$targetWidth}:{$targetHeight},gblur=sigma=30,eq=brightness=-0.12[bgout];".
            "[fg]scale=-2:{$targetHeight}[fgout];".
            '[bgout][fgout]overlay=(W-w)/2:(H-h)/2';

        $result = Process::timeout(60)->run([
            $this->ffmpegBin, '-y', '-i', $imagePath,
            '-filter_complex', $filter,
            '-frames:v', '1', '-q:v', '3', $outPath,
        ]);

        $this->assertSuccess($result, 'render pillarboxed landscape thumbnail');
    }

    /**
     * One sprite image: THUMBNAIL_STRIP_TILE_COUNT frames sampled evenly across
     * the WHOLE video and tiled left-to-right into a single row. Tile count is
     * fixed regardless of duration (a 2-minute clip and a 7-hour livestream VOD
     * both get the same number of tiles) so the sprite size stays bounded and
     * the frontend's per-pixel slice math (tile index = floor(fraction *
     * tileCount)) doesn't need to know anything about the source's actual
     * length.
     *
     * Grabs each tile with its OWN fast input seek (-ss before -i) rather than
     * one fps=N/duration filter pass over the whole file — the filter-pass
     * approach decodes from position 0 every time regardless of how far into
     * the file the sampled frames are, which for a source hours long was
     * observed to turn a "generate 100 thumbnails" call into "decode the
     * entire multi-hour video" (same root cause fixed in
     * extractWithoutSilence() — see that method's docblock for the concrete
     * numbers). N individual near-instant seeks, stitched together with a
     * second, cheap ffmpeg pass, stays fast regardless of source length.
     */
    public function generateThumbnailStrip(string $videoPath, string $outPath, float $duration, int $tileCount = self::THUMBNAIL_STRIP_TILE_COUNT): void
    {
        $this->ensureDir($outPath);
        $duration = max(1.0, $duration);
        // Never ask for more tiles than there are whole seconds of video — a
        // 30-second clip doesn't need 100 near-duplicate frames.
        $n = max(2, min($tileCount, (int) floor($duration)));
        $step = $duration / $n;
        $tileHeight = self::THUMBNAIL_STRIP_TILE_HEIGHT;

        $tmpDir = sys_get_temp_dir().'/thumbstrip_'.uniqid();
        mkdir($tmpDir, 0777, true);

        try {
            for ($i = 0; $i < $n; $i++) {
                // Midpoint of each slice rather than its start — a more
                // representative frame than landing exactly on a slice boundary.
                $t = min($duration - 0.05, ($i + 0.5) * $step);
                $framePath = sprintf('%s/frame_%04d.jpg', $tmpDir, $i);

                $result = Process::timeout(30)->run([
                    $this->ffmpegBin, '-y', '-ss', (string) $t, '-i', $videoPath,
                    '-frames:v', '1', '-vf', "scale=-1:{$tileHeight}", '-q:v', '4', $framePath,
                ]);
                $this->assertSuccess($result, "generate thumbnail strip frame {$i}");
            }

            $result = Process::timeout(60)->run([
                $this->ffmpegBin, '-y', '-f', 'image2', '-i', "{$tmpDir}/frame_%04d.jpg",
                '-vf', "tile={$n}x1", '-frames:v', '1', '-q:v', '4', $outPath,
            ]);
            $this->assertSuccess($result, 'stitch thumbnail strip');
        } finally {
            array_map('unlink', glob("{$tmpDir}/*.jpg") ?: []);
            @rmdir($tmpDir);
        }
    }

    /**
     * A single waveform PNG via ffmpeg's own showwavespic filter — no peak-data
     * computation needed client-side, just an image the timeline overlays on
     * top of the thumbnail strip. showwavespic has no direct "transparent
     * background" option, so the solid-black canvas it always draws on gets
     * keyed out to alpha afterward (colorkey) — PNG (unlike the strip's own
     * .jpg) supports that alpha channel. Reads the WHOLE audio stream once
     * (unlike the thumbnail strip, a waveform is inherently a summary of the
     * entire signal, not sample-able by seeking) — audio-only decode is cheap
     * enough even for a multi-hour source that this doesn't need the
     * seek-per-tile treatment generateThumbnailStrip() needed for video.
     */
    public function generateWaveform(string $videoPath, string $outPath, int $width = 1000, int $height = self::THUMBNAIL_STRIP_TILE_HEIGHT): void
    {
        $this->ensureDir($outPath);

        $result = Process::timeout(180)->run([
            $this->ffmpegBin, '-y', '-i', $videoPath,
            '-filter_complex', "showwavespic=s={$width}x{$height}:colors=white,format=rgba,colorkey=black:0.15:0.1",
            '-frames:v', '1', $outPath,
        ]);

        $this->assertSuccess($result, 'generate waveform');
    }

    /**
     * Detect silent gaps in [start, start+duration] of $sourcePath via ffmpeg's
     * silencedetect filter, parsed off its stderr log (it has no structured output
     * mode). -vn skips video decode entirely since only audio is analyzed here.
     * Never throws — silencedetect's "success" is just reaching EOF, and a source
     * with no silence at all is a completely normal result, not a failure.
     *
     * @return list<array{start: float, end: float}> clip-relative
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
     * segment so every kept piece comes from one decoded pass of the source —
     * but that one pass still starts at $clipStart via input seeking (-ss before
     * -i) rather than at 0, so a clip deep into a long source (e.g. a multi-hour
     * livestream VOD) doesn't force ffmpeg to decode everything before it just to
     * reach the part that matters. Without this, a clip starting tens of
     * thousands of seconds in was observed to come out with completely silent
     * audio (video too, per the same mechanism) despite the source audibly
     * having real audio at that timestamp when probed directly — decoding from
     * 0 across that much accumulated audio/video PTS drift landed trim/atrim's
     * absolute-timestamp math on the wrong (empty-sounding) content. Every
     * interval's start/end shift by $clipStart accordingly, since the seek
     * already re-bases the decoded timeline to start near 0.
     *
     * @param  list<array{start: float, end: float}>  $keepIntervals  clip-relative
     */
    /**
     * @param  array<int, array{start: float, end: float}>  $keepIntervals  ranges to keep, in order
     * @param  array<int, array{type?: string, duration?: float}>  $transitions  keyed by the KEEPINTERVAL index a crossfade should apply BEFORE (i.e. between $keepIntervals[$i-1] and $keepIntervals[$i]) — not every boundary needs an entry. Absent/'none'/'cut' at a given index means a hard cut there, byte-for-byte the same as before this parameter existed. See RenderClipJob::handle() for how this is built: only boundaries between the user's OWN segments carry a transition — the (possibly many) internal cuts AI silence-removal makes within a single one of those segments never do, or a clip with any silence at all would flicker through constant crossfades.
     */
    public function extractWithoutSilence(string $sourcePath, string $outPath, float $clipStart, array $keepIntervals, array $transitions = []): void
    {
        $this->ensureDir($outPath);

        $inputArgs = ['-ss', (string) $clipStart, '-i', $sourcePath];
        $graph = [];
        $keepIntervals = array_values($keepIntervals);
        $n = count($keepIntervals);

        // xfade requires its two video inputs to share an identical time base.
        // Every trim here comes off the SAME single input, which is enough to
        // keep them mutually consistent RIGHT AFTER trimming — but a `concat`
        // filter's output pad does NOT inherit that time base; ffmpeg assigns it
        // its own (observed: 1/1000000, against a raw trim pad's 1/12800), so a
        // boundary chain that mixes a hard-cut concat before a later crossfade
        // fails with "First input link main timebase ... do not match" unless
        // that concat output is also normalized. fps= resets a pad's time base
        // to a fixed 1/{fps} regardless of how it got there (documented ffmpeg
        // behavior — the same trick concatSegments() already relies on for the
        // same reason, joining separately-produced files there instead of one
        // concat filter's output here) — applied to every trim's video pad AND
        // re-applied after every hard-concat step, so no matter how many hard
        // cuts precede it, the input reaching an xfade is always freshly
        // normalized. Audio needs no equivalent: every atrim pad already shares
        // one sample rate/channel layout (they're all cut from the same [0:a]),
        // unlike concatSegments()'s aformat= case which joins genuinely
        // different-format files.
        $needsFpsNormalization = ! empty($transitions);

        foreach ($keepIntervals as $i => $seg) {
            $start = number_format($seg['start'], 3, '.', '');
            $end = number_format($seg['end'], 3, '.', '');
            $videoFilter = "[0:v]trim=start={$start}:end={$end},setpts=PTS-STARTPTS";
            if ($needsFpsNormalization) {
                $videoFilter .= ',fps='.self::FADE_CONCAT_FPS;
            }
            $graph[] = "{$videoFilter}[v{$i}]";
            $graph[] = "[0:a]atrim=start={$start}:end={$end},asetpts=PTS-STARTPTS[a{$i}]";
        }

        if (empty($transitions)) {
            // Byte-identical fast path to before this parameter existed — every
            // clip with a single user segment (regardless of how many pieces AI
            // silence-removal split it into) still takes this branch untouched.
            $labels = '';
            foreach ($keepIntervals as $i => $seg) {
                $labels .= "[v{$i}][a{$i}]";
            }
            $graph[] = "{$labels}concat=n={$n}:v=1:a=1[vout][aout]";
            $videoLabel = 'vout';
            $audioLabel = 'aout';
        } else {
            // Folds left to right: a boundary WITH a transition entry crossfades
            // via xfade/acrossfade (same idiom as concatSegments()'s fade path,
            // generalized to a per-boundary transition instead of one clip-wide
            // setting); every other boundary is still a plain pairwise concat.
            $videoLabel = 'v0';
            $audioLabel = 'a0';
            $cumulative = $keepIntervals[0]['end'] - $keepIntervals[0]['start'];

            for ($i = 1; $i < $n; $i++) {
                $intervalDuration = $keepIntervals[$i]['end'] - $keepIntervals[$i]['start'];
                $type = $transitions[$i]['type'] ?? 'none';

                if (in_array($type, ['fade', 'dissolve'], true)) {
                    // Clamped against both neighboring intervals (and whatever's
                    // accumulated into the output so far) so a short segment
                    // never sends the offset negative or crossfades past its own
                    // length — same safety clamp as concatSegments()'s fade path.
                    $requested = max(0.05, (float) ($transitions[$i]['duration'] ?? 0.4));
                    $pairDuration = max(0.05, min($requested, $cumulative, $intervalDuration));
                    $offset = max(0.0, $cumulative - $pairDuration);
                    $durationStr = number_format($pairDuration, 3, '.', '');
                    $offsetStr = number_format($offset, 3, '.', '');

                    $vOut = "vx{$i}";
                    $aOut = "ax{$i}";
                    $graph[] = "[{$videoLabel}][v{$i}]xfade=transition={$type}:duration={$durationStr}:offset={$offsetStr}[{$vOut}]";
                    $graph[] = "[{$audioLabel}][a{$i}]acrossfade=d={$durationStr}[{$aOut}]";
                    $videoLabel = $vOut;
                    $audioLabel = $aOut;
                    $cumulative = $cumulative + $intervalDuration - $pairDuration;
                } else {
                    $vOutRaw = "vc{$i}raw";
                    $vOut = "vc{$i}";
                    $aOut = "ac{$i}";
                    $graph[] = "[{$videoLabel}][{$audioLabel}][v{$i}][a{$i}]concat=n=2:v=1:a=1[{$vOutRaw}][{$aOut}]";
                    // Re-normalized so this pad is safe to feed into a LATER
                    // xfade too, however many hard cuts preceded it.
                    $graph[] = "[{$vOutRaw}]fps=".self::FADE_CONCAT_FPS."[{$vOut}]";
                    $videoLabel = $vOut;
                    $audioLabel = $aOut;
                    $cumulative += $intervalDuration;
                }
            }
        }

        $outputArgs = [
            // Re-encoded again by renderClip() right after, so favor quality over
            // size here to limit how much this intermediate pass compounds loss.
            '-c:v', 'libx264', '-preset', $this->x264Preset, '-crf', '18',
            '-c:a', 'aac', '-b:a', '192k',
            $outPath,
        ];

        $this->runWithFilterScript($inputArgs, $graph, ['-map', "[{$videoLabel}]", '-map', "[{$audioLabel}]"], $outputArgs, 'remove silence', 1800);
    }

    /**
     * Render a clip: trim [start,end], apply a (possibly animated) crop to reach
     * the target aspect ratio/resolution, and optionally burn in an ASS subtitle file.
     *
     * @param  array<int, array{time: float, x: float, y: float, width: float, height: float}>  $cropKeyframes  relative to clip start
     * @param  array<int, array<string, mixed>>  $layers  override-merged template layers (text/image/progress_bar/audio/rect), see LayerCompositionService. Rendered after (on top of) the caption burn-in unless a layer's z_index is negative, in which case it renders before (underneath) instead — see the split into $behindCaptionLayers/$aboveCaptionLayers inside this method.
     * @param  (callable(string): string)|null  $resolveLayerPath  resolves a layer's stored relative path to an absolute path; required if $layers references any image/audio layer
     * @param  ?array{x: float, y: float, width: float, height: float}  $videoRegion  null (default, every template before this feature) fills the whole canvas exactly as before — a smaller region insets the video into that box instead, with $canvasBackgroundColor filling the rest, so template layers (typically a 'rect' + 'text' pair) can build bars around it. Smart-pan crop keyframes and subtitle burn-in are computed against the full canvas either way — see the docblock at the call site inside this method for why.
     * @param  ?array{type?: string, intensity?: float}  $effect  null/'none' (default, every template before this feature) leaves the frame untouched — see buildEffectFilter() docblock.
     * @param  float  $speed  0.5-2.0, 1.0 (default, every clip before this feature) leaves timing untouched. Applied as the FINAL stage (after crop/caption/layers/watermark), so every earlier stage keeps computing against real, untouched time — see the setpts/atempo block near the end of this method.
     * @param  float  $volume  0.0-2.0, 1.0 (default) leaves the source clip's own audio untouched. A background-audio layer's own `volume` prop (LayerCompositionService::buildAudioLayer()) is independent of this — this only scales the clip's OWN dialogue track, not anything mixed in on top of it.
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
        ?array $effect = null,
        float $speed = 1.0,
        float $volume = 1.0,
    ): void {
        $this->ensureDir($outPath);

        $duration = max(0.1, $end - $start);

        [$graph, $videoLabel] = $this->buildCropSegments('0:v', $cropKeyframes, $duration, 'crop');
        $graph[] = "[{$videoLabel}]scale={$targetWidth}:{$targetHeight}[scaled]";
        $videoLabel = 'scaled';

        // Effect applies to the base video only — deliberately before caption/
        // layers/watermark below, same tier as the smart-pan crop above, so burned-
        // in text and branding never get zoomed/shaken along with the footage.
        [$effectGraph, $videoLabel] = $this->buildEffectFilter($videoLabel, $effect, $targetWidth, $targetHeight, $duration);
        $graph = array_merge($graph, $effectGraph);

        $inputArgs = [
            // -ss/-t must sit BEFORE their -i to bind to that input. Once a second
            // -i (the watermark) follows, a trailing -t here would instead bind to
            // THAT input — silently leaving this source clip untrimmed and reading
            // to EOF (observed: output ran to the source's full remaining length).
            '-ss', (string) $start, '-t', (string) $duration, '-i', $sourceVideoPath,
        ];
        $audioLabels = [];
        $nextInputIndex = 1;
        $layerTempFiles = [];

        // Only builds a real filter node when volume is actually non-default —
        // '0:a' stays a bare stream specifier otherwise, so the existing '0:a?'
        // (optional — tolerates a source with literally no audio track) further
        // down is unaffected for every clip that doesn't touch this new field.
        // Opting into a custom volume loses that tolerance (this filter node
        // requires a real [0:a] to exist) — an accepted trade-off, same shape as
        // every other "byte-identical unless you opt in" feature in this method.
        $sourceAudioLabel = '0:a';
        if (abs($volume - 1.0) > 0.001) {
            $vol = number_format(max(0.0, min(2.0, $volume)), 3, '.', '');
            $graph[] = "[0:a]volume={$vol}[srcaudio]";
            $sourceAudioLabel = 'srcaudio';
        }

        // Every layer used to render strictly AFTER the caption burn-in below —
        // fine for a branding bar that sits outside the caption's area, but a
        // 'rect'/'image' layer whose box overlaps the caption (e.g. a footer bar
        // built to frame it) opaquely covered the caption text, and there was no
        // way to ask for the opposite stacking regardless of the layer's own
        // z_index (z_index only orders layers against EACH OTHER, never against
        // the caption). A negative z_index now means "render before/underneath
        // the caption" instead — split here and interleave the caption burn-in
        // between the two groups. Every existing template only ever used
        // z_index >= 0 (LayerEditor's "Add layer" starts at 1), so this is a
        // no-op for them: $behindCaptionLayers is empty and behavior is
        // byte-for-byte the same as before.
        [$behindCaptionLayers, $aboveCaptionLayers] = $this->partitionLayersAroundCaption($layers);

        if (! empty($behindCaptionLayers)) {
            $built = $this->layerService->buildGraph(
                $behindCaptionLayers,
                $videoLabel,
                $targetWidth,
                $targetHeight,
                $duration,
                $resolveLayerPath ?? fn (string $p) => $p,
                $nextInputIndex,
                'behind',
            );
            $graph = array_merge($graph, $built['graph']);
            $inputArgs = array_merge($inputArgs, $built['inputArgs']);
            $videoLabel = $built['videoLabel'];
            $audioLabels = array_merge($audioLabels, $built['audioLabels']);
            $nextInputIndex += $built['inputCount'];
            $layerTempFiles = array_merge($layerTempFiles, $built['tempFiles']);
        }

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

        // Remaining (z_index >= 0) template layers render between the caption
        // burn-in above and the watermark overlay below — same tier as before
        // this method learned about "behind the caption" layers, and still the
        // ONLY tier for a template that doesn't use one — captions stay
        // implicitly at the bottom of that group, watermark implicitly at the
        // top (see LayerCompositionService docblock).
        if (! empty($aboveCaptionLayers)) {
            $built = $this->layerService->buildGraph(
                $aboveCaptionLayers,
                $videoLabel,
                $targetWidth,
                $targetHeight,
                $duration,
                $resolveLayerPath ?? fn (string $p) => $p,
                $nextInputIndex,
                'above',
            );
            $graph = array_merge($graph, $built['graph']);
            $inputArgs = array_merge($inputArgs, $built['inputArgs']);
            $videoLabel = $built['videoLabel'];
            $audioLabels = array_merge($audioLabels, $built['audioLabels']);
            $nextInputIndex += $built['inputCount'];
            $layerTempFiles = array_merge($layerTempFiles, $built['tempFiles']);
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

        // $sourceAudioLabel is '0:a' (bare stream specifier, '?' appended just
        // below) unless a custom volume built a real [srcaudio] node above.
        $audioMapLabel = $sourceAudioLabel === '0:a' ? '0:a?' : "[{$sourceAudioLabel}]";
        if (! empty($audioLabels)) {
            $mixInputs = array_merge(["[{$sourceAudioLabel}]"], $audioLabels);
            $graph[] = implode('', $mixInputs).'amix=inputs='.count($mixInputs).':duration=first:dropout_transition=0[aout]';
            $audioMapLabel = '[aout]';
        }

        // Speed is the FINAL transform, applied together to video and whatever
        // the audio chain above produced (raw/volume-adjusted/mixed) — every
        // earlier stage (crop, captions, layers, silence removal upstream of
        // this call) computed against real time, so scaling both streams by the
        // identical factor here is what keeps them in sync. Same "loses the
        // '0:a?' missing-audio tolerance only if you opt in" trade-off as the
        // volume block above, for the same reason (a real filter node needs a
        // real [0:a] to exist).
        if (abs($speed - 1.0) > 0.001) {
            $spd = number_format(max(0.5, min(2.0, $speed)), 3, '.', '');
            $graph[] = "[{$videoLabel}]setpts=PTS/{$spd}[spedvideo]";
            $videoLabel = 'spedvideo';

            $audioSourceForTempo = $audioMapLabel === '0:a?' ? '0:a' : trim($audioMapLabel, '[]');
            $graph[] = "[{$audioSourceForTempo}]atempo={$spd}[spedaudio]";
            $audioMapLabel = '[spedaudio]';
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
     * @param  ?array{type?: string, intensity?: float}  $effect  applied to the whole composited PiP/split frame, same as renderClip() — see buildEffectFilter()
     * @param  float  $speed  see renderClip() — same 0.5-2.0 setpts/atempo final stage, applied to the whole composited video and the already-mixed source+webcam+layers audio together.
     * @param  float  $volume  see renderClip() — scales only the source clip's own [0:a], not the webcam/reactor commentary track or any background-audio layer.
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
        ?array $effect = null,
        float $speed = 1.0,
        float $volume = 1.0,
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

        [$effectGraph, $outputLabel] = $this->buildEffectFilter($outputLabel, $effect, $targetWidth, $targetHeight, $duration);
        $graph = array_merge($graph, $effectGraph);

        $inputArgs = [
            // -ss/-t must each sit immediately BEFORE their own -i — with two-plus
            // inputs, a trailing -t instead binds to the NEXT -i (see renderClip()).
            '-ss', (string) $start, '-t', (string) $duration, '-i', $sourceVideoPath,
            '-t', (string) $duration, '-i', $webcamVideoPath,
        ];

        $audioLabels = [];
        $nextInputIndex = 2;
        $layerTempFiles = [];

        // Only the source clip's own track — see renderClip()'s equivalent
        // block for why this stays a bare '0:a' (used unquoted as a mixInputs
        // entry below) unless volume is actually non-default.
        $sourceAudioLabel = '0:a';
        if (abs($volume - 1.0) > 0.001) {
            $vol = number_format(max(0.0, min(2.0, $volume)), 3, '.', '');
            $graph[] = "[0:a]volume={$vol}[srcaudio]";
            $sourceAudioLabel = 'srcaudio';
        }

        // Same negative-z_index-means-behind-the-caption split as renderClip() —
        // see its docblock/comment for why. Existing templates never use a
        // negative z_index, so $behindCaptionLayers is empty for them and this
        // is a no-op.
        [$behindCaptionLayers, $aboveCaptionLayers] = $this->partitionLayersAroundCaption($layers);

        if (! empty($behindCaptionLayers)) {
            $built = $this->layerService->buildGraph(
                $behindCaptionLayers,
                $outputLabel,
                $targetWidth,
                $targetHeight,
                $duration,
                $resolveLayerPath ?? fn (string $p) => $p,
                $nextInputIndex,
                'behind',
            );
            $graph = array_merge($graph, $built['graph']);
            $inputArgs = array_merge($inputArgs, $built['inputArgs']);
            $outputLabel = $built['videoLabel'];
            $audioLabels = array_merge($audioLabels, $built['audioLabels']);
            $nextInputIndex += $built['inputCount'];
            $layerTempFiles = array_merge($layerTempFiles, $built['tempFiles']);
        }

        if ($subtitlesAssPath && file_exists($subtitlesAssPath)) {
            $escaped = $this->escapeFilterPath($subtitlesAssPath);
            $graph[] = "[{$outputLabel}]ass='{$escaped}'[captioned]";
            $outputLabel = 'captioned';
        }

        if (! empty($aboveCaptionLayers)) {
            $built = $this->layerService->buildGraph(
                $aboveCaptionLayers,
                $outputLabel,
                $targetWidth,
                $targetHeight,
                $duration,
                $resolveLayerPath ?? fn (string $p) => $p,
                $nextInputIndex,
                'above',
            );
            $graph = array_merge($graph, $built['graph']);
            $inputArgs = array_merge($inputArgs, $built['inputArgs']);
            $outputLabel = $built['videoLabel'];
            $audioLabels = array_merge($audioLabels, $built['audioLabels']);
            $nextInputIndex += $built['inputCount'];
            $layerTempFiles = array_merge($layerTempFiles, $built['tempFiles']);
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
        $mixInputs = array_merge(["[{$sourceAudioLabel}]", '[1:a]'], $audioLabels);
        $mixDuration = empty($audioLabels) ? 'shortest' : 'first';
        $graph[] = implode('', $mixInputs).'amix=inputs='.count($mixInputs).":duration={$mixDuration}:dropout_transition=0[aout]";
        $audioMapLabel = '[aout]';

        // Speed is the FINAL transform — see renderClip()'s equivalent block.
        if (abs($speed - 1.0) > 0.001) {
            $spd = number_format(max(0.5, min(2.0, $speed)), 3, '.', '');
            $graph[] = "[{$outputLabel}]setpts=PTS/{$spd}[spedvideo]";
            $outputLabel = 'spedvideo';
            $graph[] = '[aout]atempo='.$spd.'[spedaudio]';
            $audioMapLabel = '[spedaudio]';
        }

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
                ['-map', "[{$outputLabel}]", '-map', $audioMapLabel],
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
            $textTempFile = tempnam(sys_get_temp_dir(), 'covertext_').'.txt';
            file_put_contents($textTempFile, $wrapped);

            $fontSize = max(1, (int) round($targetWidth * 0.06));
            $params = [
                "textfile='".$this->escapeFilterPath($textTempFile)."'",
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
                $params[] = "fontfile='".$this->escapeFilterPath($this->defaultFontFile)."'";
            }
            $graph[] = "[{$videoLabel}]drawtext=".implode(':', $params).'[covertext]';
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
     * Renders a single still-image social/thumbnail cover from a source frame
     * (see generateThumbnail()) scaled/cropped to the target canvas, then
     * composited with: a flat color wash, a stepped darkening gradient, an
     * optional kicker label, the headline (wrapped, with per-line hugging
     * boxes and per-line accent coloring), an optional subline, and an optional
     * corner badge. See DefaultCoverTemplateConfig for the $config shape — the
     * layout math here is mirrored in the cover-template editor's live preview,
     * so any change to spacing/sizing needs to happen in both.
     *
     * Every text run goes through textfile= rather than inline text= for the
     * same crash-avoidance reason renderCoverSegment() documents.
     */
    public function renderCoverImage(
        string $imagePath,
        string $outPath,
        int $targetWidth,
        int $targetHeight,
        string $headline,
        array $config = [],
    ): void {
        $this->ensureDir($outPath);

        $background = $config['background'] ?? [];
        $kicker = $config['kicker'] ?? [];
        $textConfig = $config['text'] ?? [];
        $subline = $config['subline'] ?? [];
        $badge = $config['badge'] ?? [];

        $graph = [];
        $label = 'base';
        $graph[] = "[0:v]scale={$targetWidth}:{$targetHeight}:force_original_aspect_ratio=increase,crop={$targetWidth}:{$targetHeight}[{$label}]";

        $tmpFiles = [];
        $step = 0;
        // Each filter needs a unique output pad name — a collision silently
        // produces a broken graph rather than an error (see this file's other
        // pad-naming sites).
        $nextLabel = function () use (&$step) {
            return 'cv'.(++$step);
        };

        // --- background wash -------------------------------------------------
        $washOpacity = (float) ($background['overlay_opacity'] ?? 0);
        if ($washOpacity > 0.001) {
            $washColor = $this->toFfmpegColor($background['overlay_color'] ?? '#000000', $washOpacity);
            $out = $nextLabel();
            $graph[] = "[{$label}]drawbox=x=0:y=0:w=iw:h=ih:color={$washColor}:t=fill[{$out}]";
            $label = $out;
        }

        // --- background gradient (stepped bands) -----------------------------
        $gradient = $background['gradient'] ?? [];
        if (! empty($gradient['enabled']) && (float) ($gradient['opacity'] ?? 0) > 0.001) {
            $bands = 26;
            $gradientHeight = max(1, (int) round($targetHeight * (float) ($gradient['size'] ?? 0.45)));
            $bandHeight = (int) ceil($gradientHeight / $bands);
            $fromTop = ($gradient['position'] ?? 'bottom') === 'top';
            $maxOpacity = (float) $gradient['opacity'];

            for ($i = 0; $i < $bands; $i++) {
                // Eased ramp (squared) rather than linear — a linear stack of
                // flat bands reads as visible banding, this keeps the light end
                // subtle and concentrates the falloff near the edge.
                $t = ($i + 1) / $bands;
                $bandOpacity = round($maxOpacity * $t * $t, 4);
                $y = $fromTop
                    ? $gradientHeight - ($i + 1) * $bandHeight
                    : $targetHeight - $gradientHeight + $i * $bandHeight;
                $color = $this->toFfmpegColor($gradient['color'] ?? '#000000', $bandOpacity);
                $out = $nextLabel();
                $graph[] = "[{$label}]drawbox=x=0:y={$y}:w=iw:h={$bandHeight}:color={$color}:t=fill[{$out}]";
                $label = $out;
            }
        }

        // --- layout math (mirrored by the editor's live preview) -------------
        $headline = trim($headline);
        if (! empty($textConfig['uppercase'])) {
            $headline = mb_strtoupper($headline);
        }
        $lines = $headline === ''
            ? []
            : explode("\n", wordwrap($headline, max(6, (int) ($textConfig['wrap_chars'] ?? 16)), "\n", true));

        $fontSize = (int) ($textConfig['font_size'] ?? 0) ?: max(1, (int) round($targetWidth * (float) ($textConfig['font_scale'] ?? 0.085)));
        $lineHeight = (int) round($fontSize * 1.28);
        $linePad = (int) round($fontSize * 0.18);
        $gap = (int) round($fontSize * 0.3);
        $marginY = (int) round($targetHeight * 0.05);
        $marginX = (int) round($targetWidth * 0.055);

        $kickerOn = ! empty($kicker['enabled']) && trim((string) ($kicker['text'] ?? '')) !== '';
        $kickerFont = max(1, (int) round($targetWidth * (float) ($kicker['font_scale'] ?? 0.045)));
        $kickerPad = (int) round($kickerFont * 0.32);
        $kickerHeight = $kickerFont + 2 * $kickerPad;

        $sublineOn = ! empty($subline['enabled']) && trim((string) ($subline['text'] ?? '')) !== '';
        $sublineFont = max(1, (int) round($targetWidth * (float) ($subline['font_scale'] ?? 0.038)));
        $sublinePad = (int) round($sublineFont * 0.32);
        $sublineHeight = $sublineFont + 2 * $sublinePad;

        $blockHeight = ($kickerOn ? $kickerHeight + $gap : 0)
            + count($lines) * $lineHeight
            + ($sublineOn ? $gap + $sublineHeight : 0);

        $blockTop = match ($textConfig['position'] ?? 'bottom') {
            'top' => $marginY,
            'center' => (int) round(($targetHeight - $blockHeight) / 2),
            default => $targetHeight - $marginY - $blockHeight,
        };

        $alignLeft = ($textConfig['align'] ?? 'center') === 'left';
        $centerX = fn (int $pad) => '(main_w-text_w)/2';
        $leftX = fn (int $pad) => (string) ($marginX + $pad);
        $xFor = $alignLeft ? $leftX : $centerX;

        // --- headline band (single bar behind the whole block) ---------------
        $blockStyle = $textConfig['block_style'] ?? 'lines';
        $textBgOpacity = (float) ($textConfig['background_opacity'] ?? 0);
        if ($blockStyle === 'band' && $textBgOpacity > 0.001 && ! empty($lines)) {
            $bandColor = $this->toFfmpegColor($textConfig['background'] ?? '#000000', $textBgOpacity);
            $bandY = max(0, $blockTop - $marginY);
            $bandH = $blockHeight + 2 * $marginY;
            $out = $nextLabel();
            $graph[] = "[{$label}]drawbox=x=0:y={$bandY}:w=iw:h={$bandH}:color={$bandColor}:t=fill[{$out}]";
            $label = $out;
        }

        $cursorY = $blockTop;

        // --- kicker ----------------------------------------------------------
        if ($kickerOn) {
            $file = tempnam(sys_get_temp_dir(), 'coverkick_').'.txt';
            file_put_contents($file, mb_strtoupper(trim((string) $kicker['text'])));
            $tmpFiles[] = $file;

            $params = [
                "textfile='".$this->escapeFilterPath($file)."'",
                'expansion=none',
                "fontsize={$kickerFont}",
                'fontcolor='.$this->toFfmpegColor($kicker['color'] ?? '#111111'),
                'x='.$xFor($kickerPad),
                'y='.($cursorY + $kickerPad),
                'box=1',
                'boxcolor='.$this->toFfmpegColor($kicker['background'] ?? '#FFD100', (float) ($kicker['background_opacity'] ?? 1.0)),
                "boxborderw={$kickerPad}",
            ];
            $label = $this->appendDrawText($graph, $label, $params, $nextLabel());
            $cursorY += $kickerHeight + $gap;
        }

        // --- headline lines ---------------------------------------------------
        $highlightMode = $textConfig['highlight_mode'] ?? 'none';
        $baseColor = $this->toFfmpegColor($textConfig['color'] ?? '#FFFFFF');
        $accentColor = $this->toFfmpegColor($textConfig['highlight_color'] ?? '#FFD100');
        $strokeWidth = (int) ($textConfig['stroke_width'] ?? 0);
        $shadowX = (int) ($textConfig['shadow_x'] ?? 0);
        $shadowY = (int) ($textConfig['shadow_y'] ?? 0);
        $lineBoxColor = $this->toFfmpegColor($textConfig['background'] ?? '#000000', $textBgOpacity);

        foreach ($lines as $i => $line) {
            $file = tempnam(sys_get_temp_dir(), 'coverline_').'.txt';
            file_put_contents($file, $line);
            $tmpFiles[] = $file;

            $isAccent = match ($highlightMode) {
                'first_line' => $i === 0,
                'last_line' => $i === count($lines) - 1,
                'alternate' => $i % 2 === 1,
                default => false,
            };

            $params = [
                "textfile='".$this->escapeFilterPath($file)."'",
                'expansion=none',
                "fontsize={$fontSize}",
                'fontcolor='.($isAccent ? $accentColor : $baseColor),
                'x='.$xFor($linePad),
                'y='.($cursorY + $i * $lineHeight),
            ];
            if ($strokeWidth > 0) {
                $params[] = "borderw={$strokeWidth}";
                $params[] = 'bordercolor='.$this->toFfmpegColor($textConfig['stroke_color'] ?? '#000000');
            }
            if ($shadowX !== 0 || $shadowY !== 0) {
                $params[] = 'shadowcolor='.$this->toFfmpegColor($textConfig['shadow_color'] ?? '#000000');
                $params[] = "shadowx={$shadowX}";
                $params[] = "shadowy={$shadowY}";
            }
            if ($blockStyle === 'lines' && $textBgOpacity > 0.001) {
                $params[] = 'box=1';
                $params[] = "boxcolor={$lineBoxColor}";
                $params[] = "boxborderw={$linePad}";
            }

            $label = $this->appendDrawText($graph, $label, $params, $nextLabel());
        }
        $cursorY += count($lines) * $lineHeight;

        // --- subline ----------------------------------------------------------
        if ($sublineOn) {
            $file = tempnam(sys_get_temp_dir(), 'coversub_').'.txt';
            file_put_contents($file, trim((string) $subline['text']));
            $tmpFiles[] = $file;

            $params = [
                "textfile='".$this->escapeFilterPath($file)."'",
                'expansion=none',
                "fontsize={$sublineFont}",
                'fontcolor='.$this->toFfmpegColor($subline['color'] ?? '#FFFFFF'),
                'x='.$xFor($sublinePad),
                'y='.($cursorY + $gap + $sublinePad),
                'box=1',
                'boxcolor='.$this->toFfmpegColor($subline['background'] ?? '#E11D48', (float) ($subline['background_opacity'] ?? 1.0)),
                "boxborderw={$sublinePad}",
            ];
            $label = $this->appendDrawText($graph, $label, $params, $nextLabel());
        }

        // --- corner badge ------------------------------------------------------
        if (! empty($badge['enabled']) && trim((string) ($badge['text'] ?? '')) !== '') {
            $file = tempnam(sys_get_temp_dir(), 'coverbadge_').'.txt';
            file_put_contents($file, mb_strtoupper(trim($badge['text'])));
            $tmpFiles[] = $file;

            $badgeFont = max(1, (int) round($targetWidth * 0.045));
            $badgePad = (int) round($badgeFont * 0.35);
            $params = [
                "textfile='".$this->escapeFilterPath($file)."'",
                'expansion=none',
                "fontsize={$badgeFont}",
                'fontcolor='.$this->toFfmpegColor($badge['text_color'] ?? '#FFFFFF'),
                'x='.($marginX + $badgePad),
                'y='.($marginY + $badgePad),
                'box=1',
                'boxcolor='.$this->toFfmpegColor($badge['color'] ?? '#FF3B30'),
                "boxborderw={$badgePad}",
            ];
            $label = $this->appendDrawText($graph, $label, $params, $nextLabel());
        }

        $videoLabel = $label;

        try {
            $this->runWithFilterScript(
                ['-i', $imagePath],
                $graph,
                ['-map', "[{$videoLabel}]"],
                ['-frames:v', '1', '-q:v', '2', $outPath],
                'render cover image',
                60
            );
        } finally {
            foreach ($tmpFiles as $f) {
                if (file_exists($f)) {
                    unlink($f);
                }
            }
        }
    }

    /**
     * Appends one drawtext filter (plus this build's font file, when one is
     * configured — see renderCoverSegment()'s note on bare font= lookups) to a
     * cover's filtergraph, returning the new current pad label.
     *
     * @param  list<string>  $graph
     * @param  list<string>  $params
     */
    private function appendDrawText(array &$graph, string $inLabel, array $params, string $outLabel): string
    {
        if ($this->defaultFontFile) {
            $params[] = "fontfile='".$this->escapeFilterPath($this->defaultFontFile)."'";
        }

        $graph[] = "[{$inLabel}]drawtext=".implode(':', $params)."[{$outLabel}]";

        return $outLabel;
    }

    /**
     * #RRGGBB (validated via normalizeHexColor()) -> ffmpeg's 0xRRGGBB[@opacity]
     * color syntax, used by drawtext/drawbox params above.
     */
    private function toFfmpegColor(string $hex, float $opacity = 1.0): string
    {
        $color = '0x'.ltrim($this->normalizeHexColor($hex), '#');

        return $opacity < 1.0 ? $color.'@'.max(0.0, min(1.0, $opacity)) : $color;
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
     * @param  ?array{type?: string, duration?: float}  $transition  null/'cut' (default, every call before this feature) joins EVERY boundary with a hard cut via the concat filter, byte-for-byte the same as before. 'fade' instead chains xfade/acrossfade pairs across EVERY boundary — see the per-pair duration clamp below for why a segment shorter than the configured duration doesn't break the offset math. Superseded by $transitions when that's non-empty; kept for composeIntroOutro()'s existing "one shared setting for however many joins it has" call, and normalized into the same per-boundary shape internally.
     * @param  array<int, array{type?: string, duration?: float}>  $transitions  keyed by the SEGMENT index a crossfade should sit before (i.e. between $segmentPaths[$i-1] and $segmentPaths[$i]) — the per-boundary sibling of FFmpegService::extractWithoutSilence()'s own $transitions param, same convention. Absent/'none'/'cut' at a given index is a hard cut there. Takes precedence over $transition when non-empty.
     */
    public function concatSegments(array $segmentPaths, string $outPath, int $targetWidth, int $targetHeight, ?array $transition = null, array $transitions = []): void
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

        // The legacy single-$transition shape (one setting applied to EVERY
        // boundary) is just the per-boundary shape with the same entry repeated
        // at every index — normalizing it here means the fold below only ever
        // has to reason about one shape.
        if (empty($transitions) && ($transition['type'] ?? 'cut') === 'fade') {
            $duration = (float) ($transition['duration'] ?? 0.4);
            for ($i = 1; $i < $n; $i++) {
                $transitions[$i] = ['type' => 'fade', 'duration' => $duration];
            }
        }

        $inputArgs = [];
        $graph = [];
        $needsFpsNormalization = ! empty($transitions);

        foreach ($segmentPaths as $i => $path) {
            $inputArgs[] = '-i';
            $inputArgs[] = $path;
            $filter = "[{$i}:v]scale={$targetWidth}:{$targetHeight}:force_original_aspect_ratio=increase,crop={$targetWidth}:{$targetHeight},setsar=1";
            if ($needsFpsNormalization) {
                // xfade requires its two input pads to share an identical time
                // base — segments generated by different paths in this app (an
                // intro/outro card looped from a still image vs. a real decoded-
                // and-re-encoded clip, or two independently-rendered additional
                // video clips) end up with different container time bases even
                // at the same frame rate (observed: 1/12800 vs 1/1000000 despite
                // both being 25fps), which ffmpeg reports as "First input link
                // main timebase ... do not match" and refuses to configure the
                // filter at all. fps= resets a pad's time base to a fixed
                // 1/{fps} as a side effect (documented ffmpeg behavior),
                // applied to every segment here so any pairing matches
                // regardless of where each one came from.
                $filter .= ',fps='.self::FADE_CONCAT_FPS;
            }
            $graph[] = "{$filter}[v{$i}]";

            // Unlike fps= above, this normalization applies whenever there's
            // more than one segment at all, not just when transitions are in
            // play: the plain concat FILTER (used for a hard-cut boundary) also
            // negotiates a single common audio format across its inputs, same
            // as acrossfade does — it just doesn't hard-reject a mismatch the
            // way xfade rejects a mismatched video time base, so this failure
            // mode is silent instead of an error either way. An intro/outro
            // card's narration track (commonly 16kHz mono TTS output) chained
            // against the main clip's real audio (typically 44.1kHz stereo)
            // was observed to implicitly negotiate down to the FIRST segment's
            // (worse) format for the WHOLE output — quietly downsampling the
            // actual dialogue audio even on a hard-cut concat with no fade at
            // all. Normalizing every segment to the same sample rate/channel
            // layout up front means every filter always negotiates a chain
            // that's already uniform, instead of picking a lowest-common-
            // denominator format on their own.
            $graph[] = "[{$i}:a]aformat=sample_rates=".self::CONCAT_AUDIO_SAMPLE_RATE.':channel_layouts=stereo[a'.$i.']';
        }

        if (! empty($transitions)) {
            // xfade needs each segment's real duration up front to compute where
            // (in the growing output timeline) each crossfade should begin.
            $durations = array_map(fn (string $p) => max(0.1, $this->probeDuration($p) ?? 0.1), $segmentPaths);

            // Folds left to right, exactly mirroring
            // extractWithoutSilence()'s own per-boundary fold: a boundary WITH
            // a transition entry crossfades via xfade/acrossfade; every other
            // boundary is a plain pairwise concat, re-normalized with fps=
            // afterward so it stays safe to feed into a LATER crossfade however
            // many hard cuts precede it — see that method's docblock for the
            // concat-output-timebase bug this guards against.
            $videoLabel = 'v0';
            $audioLabel = 'a0';
            $cumulative = $durations[0];

            for ($i = 1; $i < $n; $i++) {
                $intervalDuration = $durations[$i];
                $type = $transitions[$i]['type'] ?? 'none';

                if (in_array($type, ['fade', 'dissolve'], true)) {
                    $requested = max(0.05, (float) ($transitions[$i]['duration'] ?? 0.4));
                    $pairDuration = max(0.05, min($requested, $cumulative, $intervalDuration));
                    $offset = max(0.0, $cumulative - $pairDuration);
                    $durationStr = number_format($pairDuration, 3, '.', '');
                    $offsetStr = number_format($offset, 3, '.', '');

                    $vOut = "vx{$i}";
                    $aOut = "ax{$i}";
                    $graph[] = "[{$videoLabel}][v{$i}]xfade=transition={$type}:duration={$durationStr}:offset={$offsetStr}[{$vOut}]";
                    $graph[] = "[{$audioLabel}][a{$i}]acrossfade=d={$durationStr}[{$aOut}]";
                    $videoLabel = $vOut;
                    $audioLabel = $aOut;
                    $cumulative = $cumulative + $intervalDuration - $pairDuration;
                } else {
                    $vOutRaw = "vc{$i}raw";
                    $vOut = "vc{$i}";
                    $aOut = "ac{$i}";
                    $graph[] = "[{$videoLabel}][{$audioLabel}][v{$i}][a{$i}]concat=n=2:v=1:a=1[{$vOutRaw}][{$aOut}]";
                    $graph[] = "[{$vOutRaw}]fps=".self::FADE_CONCAT_FPS."[{$vOut}]";
                    $videoLabel = $vOut;
                    $audioLabel = $aOut;
                    $cumulative += $intervalDuration;
                }
            }

            $outputArgs = [
                '-c:v', 'libx264', '-preset', $this->x264Preset, '-crf', '21',
                '-c:a', 'aac', '-b:a', '128k',
                '-movflags', '+faststart',
                $outPath,
            ];

            $this->runWithFilterScript($inputArgs, $graph, ['-map', "[{$videoLabel}]", '-map', "[{$audioLabel}]"], $outputArgs, 'concat segments (fade)', 1800);

            return;
        }

        $pairLabels = '';
        foreach ($segmentPaths as $i => $path) {
            $pairLabels .= "[v{$i}][a{$i}]";
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
    /**
     * Splits override-merged layers into the two tiers renderClip()/
     * renderReactionClip() composite around the caption burn-in.
     *
     * A negative z_index means "render before/underneath the caption" (see the
     * call sites) — z_index otherwise only orders layers against each other, never
     * against the caption, so this is the one knob that expresses that.
     *
     * 'effect'/'filter' layers are forced into the behind tier regardless of their
     * z_index: they're pixel transforms of the footage (blur, color grade,
     * vignette), and grading or blurring the burned-in caption along with the video
     * would defeat the caption's whole purpose. This matches how a CapCut-style
     * editor treats effects and filters as properties of the video track, not as
     * overlays stacked on top of everything.
     *
     * @param  array<int, array<string, mixed>>  $layers
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} [behind-caption, above-caption]
     */
    private function partitionLayersAroundCaption(array $layers): array
    {
        $isColorLayer = fn (array $l) => in_array($l['type'] ?? null, ['effect', 'filter'], true);

        return [
            array_filter($layers, fn (array $l) => $isColorLayer($l) || (float) ($l['z_index'] ?? 0) < 0),
            array_filter($layers, fn (array $l) => ! $isColorLayer($l) && (float) ($l['z_index'] ?? 0) >= 0),
        ];
    }

    private function buildPipGraph(array $cropKeyframes, float $duration, int $targetWidth, int $targetHeight, bool $right, bool $bottom): array
    {
        [$graph, $videoLabel] = $this->buildCropSegments('0:v', $cropKeyframes, $duration, 'crop');
        $graph[] = "[{$videoLabel}]scale={$targetWidth}:{$targetHeight}[base]";

        $pipSize = (int) round($targetWidth * self::PIP_SIZE_RATIO);
        $x = $right ? 'W-w-'.self::PIP_MARGIN : (string) self::PIP_MARGIN;
        $y = $bottom ? 'H-h-'.self::PIP_MARGIN : (string) self::PIP_MARGIN;

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
     * @return array{0: string[], 1: string} [graph lines, output pad label (no brackets)]
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

        $graph[] = implode('', $segLabels).'concat=n='.count($segLabels).":v=1:a=0[{$outLabel}]";

        return [$graph, $outLabel];
    }

    /**
     * Applies a formula-driven visual effect (zoom in/out, Ken Burns, shake) to
     * an already-scaled $targetWidth x $targetHeight video pad.
     *
     * @param  ?array{type?: string, intensity?: float}  $effect  null or type 'none' (every clip/template before this feature) is a no-op — returns $videoLabel unchanged.
     * @return array{0: string[], 1: string}
     */
    private function buildEffectFilter(string $videoLabel, ?array $effect, int $targetWidth, int $targetHeight, float $duration): array
    {
        $type = $effect['type'] ?? 'none';
        if (! is_string($type) || $type === 'none') {
            return [[], $videoLabel];
        }

        // Clamped well short of 1.0 so the crop window never shrinks to nothing
        // (or, for shake, never eats so much margin the source looks over-cropped).
        $intensity = max(0.02, min(0.5, (float) ($effect['intensity'] ?? 0.15)));

        return match ($type) {
            'zoom_in' => $this->buildZoomSegments($videoLabel, $targetWidth, $targetHeight, $duration, $intensity, zoomIn: true, drift: false),
            'zoom_out' => $this->buildZoomSegments($videoLabel, $targetWidth, $targetHeight, $duration, $intensity, zoomIn: false, drift: false),
            'ken_burns' => $this->buildZoomSegments($videoLabel, $targetWidth, $targetHeight, $duration, $intensity, zoomIn: true, drift: true),
            'shake' => [$this->shakeCropLines($videoLabel, $targetWidth, $targetHeight, $intensity), 'effected'],
            default => [[], $videoLabel],
        };
    }

    /**
     * Zoom in (crop window shrinks toward center over the clip, reads as moving
     * closer) or zoom out (reverse), optionally with a slow diagonal drift on top
     * (Ken Burns). Built as a sequence of small, DISCRETELY-cropped-then-rescaled
     * segments joined by concat() — same trim+setpts+concat idiom as
     * buildCropSegments() — rather than one crop filter with a time-varying w/h
     * expression: ffmpeg's crop filter only ever evaluates x/y per frame; w/h are
     * evaluated exactly once at filter init and can't reference `t` at all
     * (confirmed against this app's ffmpeg build — referencing `t` in crop's w/h
     * either hard-errors or silently freezes at whatever NaN-propagated-through-
     * min() happened to settle on, since `t` is undefined at that one evaluation).
     * Each segment instead gets its own PHP-computed, constant crop box sampled
     * at the segment's midpoint. The step count is capped so this stays cheap
     * even on a long clip; a ~0.3s step is fine-grained enough to read as a
     * smooth zoom rather than a visible staircase.
     *
     * @return array{0: string[], 1: string}
     */
    private function buildZoomSegments(string $videoLabel, int $w, int $h, float $duration, float $intensity, bool $zoomIn, bool $drift): array
    {
        $duration = max(0.1, $duration);
        $steps = (int) max(3, min(40, round($duration / 0.3)));
        $graph = [];
        $segLabels = [];
        $splitLabels = [];

        for ($i = 0; $i < $steps; $i++) {
            $splitLabels[] = "sp{$i}";
        }

        // An EXPLICIT split into $steps distinctly-labeled pads, not $steps trim
        // filters all reading the same [$videoLabel] pad directly — the implicit
        // auto-fanout ffmpeg inserts for a multiply-referenced label was observed
        // to mis-configure under this exact shape (many trims off one label, real
        // decoded H.264 input, upstream -ss) on this app's ffmpeg build: later
        // segments' crop received the ORIGINAL pre-scale source dimensions
        // instead of $w x $h, failing with "Invalid too big or non positive size"
        // even though the generated graph text was correct. An explicit split
        // sidesteps whatever internal reconfiguration path that auto-fanout hits.
        $graph[] = "[{$videoLabel}]split={$steps}".implode('', array_map(fn ($l) => "[{$l}]", $splitLabels));

        foreach ($splitLabels as $i => $inLabel) {
            $t0 = $duration * $i / $steps;
            $t1 = $i === $steps - 1 ? $duration : $duration * ($i + 1) / $steps;
            $mid = ($t0 + $t1) / 2;
            $p = min($mid / $duration, 1.0);
            $scale = $zoomIn ? (1 - $intensity * $p) : (1 - $intensity * (1 - $p));
            $scale = max(0.5, min(1.0, $scale));

            $cropW = max(2, (int) round($w * $scale));
            $cropH = max(2, (int) round($h * $scale));
            $x = (int) round(($w - $cropW) / 2);
            $y = (int) round(($h - $cropH) / 2);

            if ($drift) {
                $x = max(0, min($w - $cropW, $x + (int) round($w * $intensity * 0.5 * $p)));
                $y = max(0, min($h - $cropH, $y + (int) round($h * $intensity * 0.3 * $p)));
            }

            $label = "zoomseg{$i}";
            // setsar=1 is required, not cosmetic: each segment's crop box has a
            // slightly different aspect ratio (rounded integer pixels), so scale=
            // alone leaves each segment with a slightly different auto-computed
            // SAR even though their pixel dimensions all match $w x $h — concat
            // then refuses to join them ("Input link parameters do not match").
            $graph[] = "[{$inLabel}]trim=start={$t0}:end={$t1},setpts=PTS-STARTPTS,crop={$cropW}:{$cropH}:{$x}:{$y},scale={$w}:{$h},setsar=1[{$label}]";
            $segLabels[] = "[{$label}]";
        }

        $graph[] = implode('', $segLabels).'concat=n='.count($segLabels).':v=1:a=0[effected]';

        return [$graph, 'effected'];
    }

    /**
     * Subtle handheld-camera jitter: a FIXED (not time-varying — see
     * buildZoomSegments() docblock for why crop's w/h can't vary with `t`) shrink
     * leaves permanent margin room for the crop window to oscillate within via
     * sin/cos on x/y, which crop genuinely does re-evaluate per frame. Amplitude
     * is kept to 45%/35% of that margin (not 50%) as a small floating-point
     * safety buffer against the sin/cos extremes landing exactly on the crop's
     * own edge.
     *
     * @return string[]
     */
    private function shakeCropLines(string $videoLabel, int $w, int $h, float $intensity): array
    {
        $margin = min(0.3, $intensity * 1.5);
        $cropW = max(2, (int) round($w * (1 - $margin)));
        $cropH = max(2, (int) round($h * (1 - $margin)));
        $ampX = number_format($margin * 0.45, 4, '.', '');
        $ampY = number_format($margin * 0.35, 4, '.', '');

        $x = "(({$w}-{$cropW})/2+{$w}*{$ampX}*sin(t*14))";
        $y = "(({$h}-{$cropH})/2+{$h}*{$ampY}*cos(t*11))";

        return [
            "[{$videoLabel}]crop=w={$cropW}:h={$cropH}:x='{$x}':y='{$y}'[shaken]",
            "[shaken]scale={$w}:{$h}[effected]",
        ];
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
            throw new RuntimeException("ffmpeg failed to {$action}: ".$result->errorOutput());
        }
    }
}
