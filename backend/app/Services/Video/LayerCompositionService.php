<?php

namespace App\Services\Video;

/**
 * Turns a template's config['layers'] (already override-merged by
 * LayerOverrideMerger) into FFmpeg filtergraph lines — the layer-rendering
 * sibling of SubtitleService (which does the same "config -> filter" job for
 * captions). Called from FFmpegService::renderClip(), which owns the actual
 * Process/runWithFilterScript() invocation; this class only builds strings.
 *
 * Layer shape (see the plan doc / template config version 2):
 *   { id, type, z_index, x, y, width, height, opacity, timing: {start, end}, props }
 * x/y/width/height are 0..1 fractions of the target resolution so a layer's
 * position survives aspect-ratio/resolution changes. All video-affecting layers
 * are gated by an `enable='between(t,start,end)'` clause, which is O(1) per layer
 * (unlike buildCropSegments()'s trim+concat approach, not needed here since
 * `enable` is a boolean gate, not an interpolated value).
 */
class LayerCompositionService
{
    public function __construct(
        // See config('services.media.default_font_file'). Null is fine on a machine
        // whose ffmpeg build has a working fontconfig — buildTextLayer() falls back
        // to drawtext's font= (fontconfig name lookup) in that case.
        private readonly ?string $defaultFontFile = null,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $layers  override-merged, any order (sorted internally by z_index)
     * @param  string  $videoLabel  current video pad label, no brackets (e.g. 'scaled' or 'captioned')
     * @param  callable(string): string  $resolvePath  resolves a layer's stored relative path (e.g. media disk) to an absolute filesystem path
     * @param  string  $labelPrefix  disambiguates this call's pad labels from another call's. Pad names are derived from each layer's INDEX in the array it was handed, so two calls in the same filtergraph — FFmpegService renders the layers below the caption burn-in separately from those above it — would otherwise both emit a pad called "layer0", and the final -map would silently pick the first one. Callers that build only one group can leave it empty.
     * @return array{graph: string[], inputArgs: string[], videoLabel: string, audioLabels: string[], inputCount: int, tempFiles: string[]}
     */
    public function buildGraph(
        array $layers,
        string $videoLabel,
        int $targetWidth,
        int $targetHeight,
        float $duration,
        callable $resolvePath,
        int $nextInputIndex,
        string $labelPrefix = '',
    ): array {
        $layers = $this->sortByZIndex($layers);

        $graph = [];
        $inputArgs = [];
        $audioLabels = [];
        $inputCount = 0;
        // Populated by 'text' layers below (see buildTextLayer()'s docblock for why
        // drawtext reads from a temp file instead of an inline, escaped value) —
        // the caller (FFmpegService::renderClip()/renderReactionClip()) deletes
        // these once the ffmpeg process that actually reads them has finished.
        $tempFiles = [];

        foreach ($layers as $i => $layer) {
            $type = $layer['type'] ?? null;
            $suffix = $labelPrefix.$i;

            switch ($type) {
                case 'text':
                    [$lines, $videoLabel, $tempFile] = $this->buildTextLayer($layer, $videoLabel, $targetWidth, $targetHeight, $duration, $suffix);
                    $graph = array_merge($graph, $lines);
                    if ($tempFile) {
                        $tempFiles[] = $tempFile;
                    }
                    break;

                case 'rect':
                    [$lines, $videoLabel] = $this->buildRectLayer($layer, $videoLabel, $duration, $suffix);
                    $graph = array_merge($graph, $lines);
                    break;

                case 'image':
                case 'logo':
                    $imagePath = (string) ($layer['props']['image_path'] ?? '');
                    if ($imagePath === '') {
                        break;
                    }
                    $inputIndex = $nextInputIndex + $inputCount;
                    $resolvedImagePath = $resolvePath($imagePath);
                    [$lines, $videoLabel] = $this->buildImageLayer($layer, $videoLabel, $targetWidth, $duration, $inputIndex, $suffix, $resolvedImagePath);
                    $graph = array_merge($graph, $lines);
                    $inputArgs[] = '-i';
                    $inputArgs[] = $resolvedImagePath;
                    $inputCount++;
                    break;

                case 'progress_bar':
                    [$lines, $videoLabel] = $this->buildProgressBarLayer($layer, $videoLabel, $duration, $suffix);
                    $graph = array_merge($graph, $lines);
                    break;

                case 'effect':
                case 'filter':
                    [$lines, $videoLabel] = $this->buildColorLayer($layer, $videoLabel, $duration, $suffix);
                    $graph = array_merge($graph, $lines);
                    break;

                case 'audio':
                    $audioPath = (string) ($layer['props']['audio_path'] ?? '');
                    if ($audioPath === '') {
                        break;
                    }
                    $inputIndex = $nextInputIndex + $inputCount;
                    [$lines, $label] = $this->buildAudioLayer($layer, $duration, $inputIndex, $suffix);
                    $graph = array_merge($graph, $lines);
                    $audioLabels[] = $label;
                    $inputArgs[] = '-i';
                    $inputArgs[] = $resolvePath($audioPath);
                    $inputCount++;
                    break;

                case 'pip_video':
                    // Only supported source in this pass is the reaction webcam
                    // recording, which RenderClipJob already renders via the
                    // separate renderReactionClip()/buildPipGraph() path (and skips
                    // this layer type entirely whenever webcam_path is set, to avoid
                    // two competing PiP mechanisms on the same clip — see
                    // RenderClipJob's precedence comment). A general "any second
                    // uploaded video" PiP source is deliberately out of scope here;
                    // this case intentionally no-ops rather than half-implementing it.
                    break;

                default:
                    // Unknown/future layer type: ignore rather than fail the render.
                    break;
            }
        }

        return [
            'graph' => $graph,
            'inputArgs' => $inputArgs,
            'videoLabel' => $videoLabel,
            'audioLabels' => $audioLabels,
            'inputCount' => $inputCount,
            'tempFiles' => $tempFiles,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sortByZIndex(array $layers): array
    {
        $indexed = array_values($layers);
        usort($indexed, function ($a, $b) {
            return ($a['z_index'] ?? 0) <=> ($b['z_index'] ?? 0);
        });

        return $indexed;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function timingWindow(array $layer, float $duration): array
    {
        $start = max(0.0, (float) ($layer['timing']['start'] ?? 0));
        $end = $layer['timing']['end'] ?? null;
        $end = $end === null ? $duration : min((float) $end, $duration);
        if ($end <= $start) {
            $end = $start + 0.1;
        }

        return [$start, $end];
    }

    private function clampOpacity(mixed $opacity): string
    {
        $value = max(0.0, min(1.0, (float) $opacity));

        return number_format($value, 3, '.', '');
    }

    /**
     * Validates a #RRGGBB(AA) color string against a strict hex pattern before it
     * reaches a filter argument — colors come from user-editable JSON via the API,
     * and unlike text content (escaped via escapeDrawtext()) a color isn't quoted,
     * so this is the injection guard for this field.
     */
    private function normalizeColor(mixed $color, string $default = '#FFFFFF'): string
    {
        $color = is_string($color) ? $color : $default;
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return $default;
        }

        return $color;
    }

    /**
     * Escapes a literal apostrophe for use inside drawtext's text='...' value.
     * A backslash-escaped quote (\') is NOT how ffmpeg's filtergraph parser quotes
     * a ' inside a '...'-quoted string — confirmed by direct reproduction that it
     * segfaults ffmpeg (not just a parse error) once the text has two or more of
     * them, e.g. any quoted phrase or contraction. The correct form closes the
     * quoting, inserts an escaped quote, and reopens it: '\''. Everything else
     * (backslash, colon, percent) is already literal inside '...' — escaping those
     * too, as this used to, just made them show up as visible stray backslashes in
     * the rendered caption.
     */
    private function escapeDrawtext(string $text): string
    {
        return str_replace("'", "'\\''", $text);
    }

    private function escapeFontPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return str_replace(':', '\\:', $path);
    }

    /**
     * @return array{0: string[], 1: string, 2: ?string} [graph lines, output pad label, temp textfile path to delete after the render (or null if content was empty)]
     */
    private function buildTextLayer(array $layer, string $videoLabel, int $targetWidth, int $targetHeight, float $duration, string $suffix): array
    {
        $props = $layer['props'] ?? [];
        $content = (string) ($props['content'] ?? '');
        $hasBox = isset($layer['width']) || isset($layer['height']);
        $boxWidthFrac = (float) ($layer['width'] ?? 1.0);
        $boxHeightPx = isset($layer['height']) ? (float) $layer['height'] * $targetHeight : null;

        // Manual font_size always wins when set (today's only mode). Omitting it
        // while the layer has a width/height (the bar-text case — see the 'rect'
        // layer type this is meant to pair with) auto-scales the font to fit that
        // box instead of a fixed default that could overflow a short bar or look
        // tiny in a tall one. ffmpeg can't dry-run drawtext to true-fit text, so
        // this is a heuristic, not exact measurement — and deliberately NOT derived
        // from "assume one line fills the whole box height": for a realistic multi-
        // word headline that assumption produces a huge single-line size, which
        // then forces an equally tiny chars-per-line wrap width, which wraps into
        // many short lines, which — if the height constraint were applied to THAT
        // starting size — never converges on anything sane. Instead: pick a size
        // proportional to canvas width first (same flavor of estimate
        // renderCoverSegment() uses for its own text), wrap at that size, then only
        // shrink (never grow) if the resulting line count doesn't fit the box.
        $manualFontSize = isset($props['font_size']) ? max(1, (int) $props['font_size']) : null;
        $baseFontSize = $manualFontSize ?? max(16, min(200, (int) round($targetWidth * 0.05)));

        if ($hasBox && trim($content) !== '') {
            $charsPerLine = max(6, (int) round(($boxWidthFrac * $targetWidth) / ($baseFontSize * 0.55)));
            $content = wordwrap(trim($content), $charsPerLine, "\n", true);
        }

        if ($manualFontSize !== null) {
            $fontSize = $manualFontSize;
        } elseif ($boxHeightPx !== null) {
            $lineCount = max(1, substr_count($content, "\n") + 1);
            $fontSize = max(16, min($baseFontSize, (int) round($boxHeightPx * 0.85 / ($lineCount * 1.2))));
        } else {
            $fontSize = $baseFontSize;
        }

        $color = $this->normalizeColor($props['color'] ?? '#FFFFFF');
        $x = max(0.0, min(1.0, (float) ($layer['x'] ?? 0.5)));
        $y = max(0.0, min(1.0, (float) ($layer['y'] ?? 0.5)));
        $align = $props['align'] ?? 'center';
        $opacity = $this->clampOpacity($layer['opacity'] ?? 1.0);

        $xExpr = match ($align) {
            'left' => sprintf('(main_w*%s)', $x),
            'right' => sprintf('(main_w*%s)-text_w', $x),
            default => sprintf('(main_w*%s)-(text_w/2)', $x),
        };
        $yExpr = sprintf('(main_h*%s)-(text_h/2)', $y);

        // textfile= (raw content, verbatim) instead of text='...' (escaped, inline)
        // — drawtext's own text='...' escaping (backslash before a special char, or
        // closing/reopening the quote for a literal ') turned out to reliably
        // segfault this ffmpeg build once a piece of text combines more than one
        // escaped character (e.g. an apostrophe together with a colon or percent —
        // both individually fine, but not together). textfile= sidesteps the whole
        // class of bug: the content never touches the filtergraph string at all, so
        // nothing in it needs escaping. expansion=none additionally stops drawtext
        // from interpreting a literal '%' as the start of a %{...} directive.
        $tempFile = tempnam(sys_get_temp_dir(), 'drawtext_').'.txt';
        file_put_contents($tempFile, $content);

        $params = [
            "textfile='".$this->escapeFontPath($tempFile)."'",
            'expansion=none',
            "fontsize={$fontSize}",
            "fontcolor={$color}",
            "x={$xExpr}",
            "y={$yExpr}",
            "alpha={$opacity}",
        ];

        $fontFile = (! empty($props['font_file']) && is_string($props['font_file']))
            ? $props['font_file']
            : $this->defaultFontFile;

        if ($fontFile) {
            $params[] = "fontfile='".$this->escapeFontPath($fontFile)."'";
        } elseif (! empty($props['font']) && is_string($props['font'])) {
            // Bare font-name resolution needs a working fontconfig on the ffmpeg
            // host (see the class constructor docblock) — only reached when no
            // font_file override and no configured default_font_file exist.
            $params[] = 'font='.$this->escapeDrawtext($props['font']);
        }

        if (! empty($props['stroke_color']) && ! empty($props['stroke_width'])) {
            $params[] = 'bordercolor='.$this->normalizeColor($props['stroke_color'], '#000000');
            $params[] = 'borderw='.max(0, (int) $props['stroke_width']);
        }

        if (! empty($props['background'])) {
            $bgOpacity = $this->clampOpacity($props['background_opacity'] ?? 0.5);
            $params[] = 'box=1';
            $params[] = 'boxcolor='.$this->normalizeColor($props['background'], '#000000').'@'.$bgOpacity;
            $params[] = 'boxborderw=8';
        }

        [$start, $end] = $this->timingWindow($layer, $duration);
        $params[] = "enable='between(t,{$start},{$end})'";

        $outLabel = "layer{$suffix}";
        $line = "[{$videoLabel}]drawtext=".implode(':', $params)."[{$outLabel}]";

        return [[$line], $outLabel, $tempFile];
    }

    /**
     * @param  string|null  $resolvedPath  absolute path to the image, used only to measure it for rotation (see below)
     * @return array{0: string[], 1: string}
     */
    private function buildImageLayer(array $layer, string $videoLabel, int $targetWidth, float $duration, int $inputIndex, string $suffix, ?string $resolvedPath = null): array
    {
        $x = max(0.0, min(1.0, (float) ($layer['x'] ?? 0)));
        $y = max(0.0, min(1.0, (float) ($layer['y'] ?? 0)));
        $widthFrac = $layer['width'] ?? null;
        $opacity = $this->clampOpacity($layer['opacity'] ?? 1.0);
        $rotation = (float) ($layer['rotation'] ?? 0);

        $graph = [];
        $sourceLabel = "{$inputIndex}:v";
        $pxWidth = null;

        if ($widthFrac !== null) {
            $pxWidth = max(1, (int) round($targetWidth * (float) $widthFrac));
            $scaledLabel = "layer{$suffix}scaled";
            $graph[] = "[{$sourceLabel}]scale={$pxWidth}:-1[{$scaledLabel}]";
            $sourceLabel = $scaledLabel;
        }

        $rgbaLabel = "layer{$suffix}rgba";
        $graph[] = "[{$sourceLabel}]format=rgba,colorchannelmixer=aa={$opacity}[{$rgbaLabel}]";

        $xExpr = sprintf('main_w*%s', $x);
        $yExpr = sprintf('main_h*%s', $y);

        // Rotation, for stickers. `rotate` has to grow its output to fit the
        // turned image or the corners get cut off, and overlay anchors that
        // (now larger) box by its top-left — so a naive rotate visibly drags the
        // sticker up and to the left as the angle increases. Compensating for it
        // needs the pre-rotation pixel size, which is why the image is measured
        // here: FFmpeg can compute the grown box itself (rotw/roth), but there's
        // no expression for "where the untouched image's top-left used to be".
        //
        // Rotating about the layer's own centre is also what the CSS preview
        // does by default, so the two agree without either side special-casing
        // the other. If the file can't be measured, rotation is skipped rather
        // than applied at a position that would be wrong.
        $sourceSize = $rotation != 0.0 && $resolvedPath ? @getimagesize($resolvedPath) : false;

        if ($rotation != 0.0 && $sourceSize) {
            $boxWidth = (float) ($pxWidth ?? $sourceSize[0]);
            $boxHeight = $boxWidth * ((float) $sourceSize[1] / max(1, (float) $sourceSize[0]));

            $radians = deg2rad($rotation);
            $cos = abs(cos($radians));
            $sin = abs(sin($radians));
            // Rounded to whole pixels, and the SAME rounded values feed both the
            // filter and the offset below — computing the offset from the exact
            // float while handing ffmpeg a rounded box would leave the two
            // disagreeing by up to a pixel. (cos(pi/2) is 6.1e-17 rather than 0
            // in binary floating point, so at exact right angles an unrounded
            // ceil() would even allocate a whole spurious pixel.)
            $grownWidth = (float) (int) round($boxWidth * $cos + $boxHeight * $sin);
            $grownHeight = (float) (int) round($boxWidth * $sin + $boxHeight * $cos);

            $rotatedLabel = "layer{$suffix}rot";
            $graph[] = sprintf(
                '[%s]rotate=%s:c=none:ow=%d:oh=%d[%s]',
                $rgbaLabel,
                number_format($radians, 6, '.', ''),
                (int) $grownWidth,
                (int) $grownHeight,
                $rotatedLabel
            );
            $rgbaLabel = $rotatedLabel;

            // Shift back by half the growth on each axis, so the centre of the
            // sticker stays exactly where it was before it was turned.
            $xExpr = sprintf('main_w*%s%+.2f', $x, ($boxWidth - $grownWidth) / 2);
            $yExpr = sprintf('main_h*%s%+.2f', $y, ($boxHeight - $grownHeight) / 2);
        }

        [$start, $end] = $this->timingWindow($layer, $duration);
        $outLabel = "layer{$suffix}";
        $graph[] = "[{$videoLabel}][{$rgbaLabel}]overlay={$xExpr}:{$yExpr}:enable='between(t,{$start},{$end})'[{$outLabel}]";

        return [$graph, $outLabel];
    }

    /**
     * A plain solid-color rectangle — same drawbox idiom as buildProgressBarLayer()
     * below, generalized to an arbitrary box instead of a fixed-height full-width
     * strip. Meant to pair with a 'text' layer sized/positioned inside it (see
     * buildTextLayer()'s auto-size mode) to build a "headline bar"/"branding bar"
     * look — drawtext's own box=1 background can't do this since it always
     * auto-sizes to the rendered text, not an independently configurable box.
     * x/y are top-left fractions (same convention buildImageLayer() uses for
     * overlay=), unlike 'text'/'progress_bar' layers which treat x as a center or
     * position-enum — each layer type documents its own x/y meaning already.
     *
     * @return array{0: string[], 1: string}
     */
    private function buildRectLayer(array $layer, string $videoLabel, float $duration, string $suffix): array
    {
        $props = $layer['props'] ?? [];
        $color = $this->normalizeColor($props['color'] ?? '#000000', '#000000');
        $opacity = $this->clampOpacity($layer['opacity'] ?? 1.0);
        $x = max(0.0, min(1.0, (float) ($layer['x'] ?? 0.0)));
        $y = max(0.0, min(1.0, (float) ($layer['y'] ?? 0.0)));
        $width = max(0.0, min(1.0, (float) ($layer['width'] ?? 1.0)));
        $height = max(0.0, min(1.0, (float) ($layer['height'] ?? 0.1)));

        [$start, $end] = $this->timingWindow($layer, $duration);
        $enable = "between(t,{$start},{$end})";
        $outLabel = "layer{$suffix}";

        $line = "[{$videoLabel}]drawbox=x=iw*{$x}:y=ih*{$y}:w=iw*{$width}:h=ih*{$height}:color={$color}@{$opacity}:t=fill:enable='{$enable}'[{$outLabel}]";

        return [[$line], $outLabel];
    }

    /**
     * @return array{0: string[], 1: string}
     */
    private function buildProgressBarLayer(array $layer, string $videoLabel, float $duration, string $suffix): array
    {
        $props = $layer['props'] ?? [];
        $color = $this->normalizeColor($props['color'] ?? '#7C5CFF', '#7C5CFF');
        $bgColor = $this->normalizeColor($props['background_color'] ?? '#000000', '#000000');
        $heightPx = max(1, (int) ($props['height_px'] ?? 6));
        $y = ($props['position'] ?? 'bottom') === 'top' ? '0' : "ih-{$heightPx}";

        [$start, $end] = $this->timingWindow($layer, $duration);
        $enable = "between(t,{$start},{$end})";
        $span = max(0.001, $end - $start);

        $bgLabel = "layer{$suffix}bg";
        $fgLabel = "layer{$suffix}";

        return [[
            "[{$videoLabel}]drawbox=x=0:y={$y}:w=iw:h={$heightPx}:color={$bgColor}@0.5:t=fill:enable='{$enable}'[{$bgLabel}]",
            "[{$bgLabel}]drawbox=x=0:y={$y}:w='iw*(t-{$start})/{$span}':h={$heightPx}:color={$color}@1:t=fill:enable='{$enable}'[{$fgLabel}]",
        ], $fgLabel];
    }

    /**
     * The one FFmpeg-side implementation behind both the editor's "Effects" and
     * "Filters" panels — they differ in intent and default timing, not mechanism.
     * An 'effect' is a single timed adjustment (blur a face for three seconds); a
     * 'filter' is a named color-grade preset that normally spans the whole clip.
     * Both are pure pixel transforms of whatever video pad they're handed, so both
     * are just a filter chain gated by the same `enable='between(t,s,e)'` clause
     * every other layer type uses — no second rendering path, and a filter that
     * *is* given a shorter timing window simply grades only that stretch.
     *
     * Every filter used here was checked to declare timeline support (`enable`);
     * ffmpeg hard-errors on a filter that doesn't, so an unrecognized preset
     * no-ops rather than guessing at a chain.
     *
     * @return array{0: string[], 1: string}
     */
    private function buildColorLayer(array $layer, string $videoLabel, float $duration, string $suffix): array
    {
        $props = $layer['props'] ?? [];
        // 0..1, scales however strong the named look is — 1.0 is the preset as
        // designed, 0 leaves the frame untouched (so the slider bottoms out at
        // "off" rather than at some arbitrary weakest-still-visible grade).
        $intensity = max(0.0, min(1.0, (float) ($props['intensity'] ?? 1.0)));
        if ($intensity <= 0.001) {
            return [[], $videoLabel];
        }

        $name = (string) ($layer['type'] === 'filter' ? ($props['preset'] ?? 'normal') : ($props['effect'] ?? 'none'));

        // number_format keeps these locale-independent: a filtergraph argument
        // written with a comma decimal separator (de_DE, id_ID, ...) would be
        // parsed by ffmpeg as an argument separator instead.
        $n = fn (float $v): string => number_format($v, 3, '.', '');

        $chain = match ($name) {
            // --- effects (timed) ---
            'blur' => 'gblur=sigma='.$n(1 + 19 * $intensity),
            'grayscale' => 'hue=s='.$n(1 - $intensity),
            'brightness' => 'eq=brightness='.$n(0.3 * $intensity),
            'contrast' => 'eq=contrast='.$n(1 + 1.0 * $intensity),
            'vignette' => 'vignette=angle='.$n(0.35 + 0.85 * $intensity),
            // --- filters (color-grade presets) ---
            'warm' => 'colorbalance=rs='.$n(0.15 * $intensity).':gs='.$n(0.03 * $intensity).':bs='.$n(-0.12 * $intensity)
                .',eq=saturation='.$n(1 + 0.15 * $intensity),
            'cool' => 'colorbalance=rs='.$n(-0.12 * $intensity).':bs='.$n(0.16 * $intensity)
                .',eq=saturation='.$n(1 + 0.05 * $intensity),
            'bw' => 'hue=s='.$n(1 - $intensity).',eq=contrast='.$n(1 + 0.2 * $intensity),
            'vintage' => 'curves=preset=vintage,eq=saturation='.$n(1 - 0.25 * $intensity),
            'high_contrast' => 'eq=contrast='.$n(1 + 0.5 * $intensity).':saturation='.$n(1 + 0.2 * $intensity),
            // 'normal'/'none' and anything unrecognized: leave the frame alone.
            default => null,
        };

        if ($chain === null) {
            return [[], $videoLabel];
        }

        [$start, $end] = $this->timingWindow($layer, $duration);
        $enable = "enable='between(t,{$start},{$end})'";

        // The gate has to be repeated per filter in the chain (it's a per-filter
        // option, not a chain-level one) — otherwise only the last link would be
        // time-limited and the rest would apply for the whole clip.
        $gated = implode(',', array_map(
            fn (string $f) => $f.':'.$enable,
            explode(',', $chain)
        ));

        $outLabel = "layer{$suffix}";

        return [["[{$videoLabel}]{$gated}[{$outLabel}]"], $outLabel];
    }

    /**
     * @return array{0: string[], 1: string} [graph lines, audio pad label with brackets, e.g. "[bg0]"]
     */
    private function buildAudioLayer(array $layer, float $duration, int $inputIndex, string $suffix): array
    {
        $props = $layer['props'] ?? [];
        $volume = max(0.0, min(2.0, (float) ($props['volume'] ?? 1.0)));
        $fadeIn = max(0.0, (float) ($props['fade_in'] ?? 0));
        $fadeOut = max(0.0, (float) ($props['fade_out'] ?? 0));

        $chain = "[{$inputIndex}:a]volume={$volume}";
        if ($fadeIn > 0) {
            $chain .= ",afade=t=in:st=0:d={$fadeIn}";
        }
        if ($fadeOut > 0) {
            $fadeStart = max(0, $duration - $fadeOut);
            $chain .= ",afade=t=out:st={$fadeStart}:d={$fadeOut}";
        }

        $label = "bg{$suffix}";
        $chain .= "[{$label}]";

        return [[$chain], "[{$label}]"];
    }
}
