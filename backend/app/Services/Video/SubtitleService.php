<?php

namespace App\Services\Video;

class SubtitleService
{
    // Below this, a word's own on-screen slice is too short to read and — far
    // more often — isn't genuine rapid speech at all but a faster-whisper
    // word-alignment artifact: several consecutive words collapse to
    // near-identical start/end timestamps (seen concretely as a cluster of
    // words all within ~0.01-0.5s of each other). Rendered as-is, each gets
    // its own near-instant ASS cue, which reads as the caption flickering /
    // jumping back and forth. repairDegenerateTiming() below spreads any such
    // cluster evenly across its own span instead of trusting the raw timestamps.
    private const MIN_WORD_SECONDS = 0.12;

    // Above this many characters, a chunk wraps onto a second line instead of
    // running off-screen or shrinking to fit — see wrapDense().
    private const MAX_LINE_CHARS = 24;

    /**
     * Build word-level segments (relative to the clip) from absolute transcript words,
     * grouped into short on-screen phrases (chunks) for punchy short-form captions.
     *
     * @param  array<int, array{word: string, start: float, end: float}>  $words  absolute timestamps
     * @return array<int, array{start: float, end: float, text: string, words: array}>
     */
    public function buildClipSegments(array $words, float $clipStart, float $clipEnd, int $wordsPerChunk = 3): array
    {
        $relevant = array_values(array_filter(
            $words,
            fn ($w) => $w['end'] > $clipStart && $w['start'] < $clipEnd
        ));

        $relative = array_map(fn ($w) => [
            'word' => $w['word'],
            'start' => round(max(0, $w['start'] - $clipStart), 2),
            'end' => round(min($clipEnd - $clipStart, $w['end'] - $clipStart), 2),
        ], $relevant);

        $relative = $this->repairDegenerateTiming($relative, $clipEnd - $clipStart);

        $chunks = [];
        $buffer = [];

        foreach ($relative as $w) {
            $buffer[] = $w;

            if (count($buffer) >= $wordsPerChunk) {
                $chunks[] = $this->finalizeChunk($buffer);
                $buffer = [];
            }
        }

        if (! empty($buffer)) {
            $chunks[] = $this->finalizeChunk($buffer);
        }

        return $chunks;
    }

    /**
     * Enforces a minimum, non-overlapping on-screen duration for every word by
     * scanning left-to-right and pushing any word that would start before the
     * previous one's (possibly already-extended) end forward just enough. This
     * resolves literal timestamp overlaps and — far more commonly — repairs
     * clusters of near-identical timestamps (the faster-whisper alignment
     * artifact described above) that would otherwise flash by as a stream of
     * near-instant, flickering captions.
     *
     * A cluster's forced delay isn't capped against "the next word's original
     * start" — there frequently isn't enough room there either, since the
     * whole degenerate region is compressed. Instead it's left to catch back
     * up naturally: the next time a word's own raw start already exceeds the
     * running cursor (i.e. a normal gap/pause in speech), the push-forward
     * stops propagating on its own. Clamped to $clipRelativeEnd so a long run
     * of pushes can never spill past the clip's own end.
     *
     * @param  array<int, array{word: string, start: float, end: float}>  $words  clip-relative
     * @return array<int, array{word: string, start: float, end: float}>
     */
    private function repairDegenerateTiming(array $words, float $clipRelativeEnd): array
    {
        $cursor = 0.0;

        foreach ($words as &$w) {
            $start = round(max($w['start'], $cursor), 2);
            $end = round(max($w['end'], $start + self::MIN_WORD_SECONDS), 2);
            $end = min($end, $clipRelativeEnd);
            $start = min($start, $end);

            $w['start'] = $start;
            $w['end'] = $end;
            $cursor = $end;
        }
        unset($w);

        return $words;
    }

    private function finalizeChunk(array $buffer): array
    {
        return [
            'start' => $buffer[0]['start'],
            'end' => end($buffer)['end'],
            'text' => implode(' ', array_column($buffer, 'word')),
            'words' => $buffer,
        ];
    }

    public function toSrt(array $segments): string
    {
        $lines = [];
        foreach ($segments as $i => $seg) {
            $lines[] = (string) ($i + 1);
            $lines[] = $this->srtTime($seg['start']) . ' --> ' . $this->srtTime($seg['end']);
            $lines[] = $seg['text'];
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Render an ASS subtitle file. When $config['highlight_active_word'] is true,
     * each chunk is re-emitted once per word with only the currently-spoken word
     * recolored, producing TikTok-style word-by-word highlighting.
     */
    public function toAss(array $segments, array $config, int $videoWidth, int $videoHeight): string
    {
        $font = $config['font'] ?? 'Arial';
        $fontSize = (int) ($config['font_size'] ?? max(36, (int) round($videoHeight / 20)));
        $color = $this->hexToAss($config['color'] ?? '#FFFFFF');
        $highlightColor = $this->hexToAss($config['highlight_color'] ?? '#FFD100');
        $strokeColor = $this->hexToAss($config['stroke_color'] ?? '#000000');
        $backgroundOpacity = (float) ($config['background_opacity'] ?? 0);
        $backgroundColorAss = $this->hexToAss($config['background'] ?? '#000000', $backgroundOpacity);
        $outline = (int) ($config['stroke_width'] ?? 3);
        $bold = ! empty($config['bold']) ? -1 : 0;
        $italic = ! empty($config['italic']) ? -1 : 0;
        $alignment = match ($config['position'] ?? 'bottom') {
            'top' => 8,
            'center', 'middle' => 5,
            default => 2,
        };
        $marginV = (int) ($config['margin_v'] ?? round($videoHeight * 0.08));
        $uppercase = ! empty($config['uppercase']);
        $highlightActiveWord = ! empty($config['highlight_active_word']);
        $animation = $config['animation'] ?? 'none';

        // ASS BorderStyle: 1 draws Outline as a per-glyph stroke using OutlineColour
        // (the normal "outlined text" look); 3 instead fills a solid box behind the
        // whole line, with Outline reinterpreted as the box's padding — but per
        // libass's actual behavior (verified by rendering a test frame), the box is
        // filled from OutlineColour, *not* BackColour, despite what the field name
        // suggests. libass only supports one or the other per style — a background
        // box replaces the glyph stroke rather than layering with it, which is also
        // how virtually every short-form caption tool renders its "highlight box"
        // style, since the box itself already provides contrast against the video.
        $backgroundEnabled = $backgroundOpacity > 0;
        $borderStyle = $backgroundEnabled ? 3 : 1;
        $boxPadding = $backgroundEnabled ? (int) ($config['background_padding'] ?? max($outline, 8)) : $outline;
        $outlineColor = $backgroundEnabled ? $backgroundColorAss : $strokeColor;

        $header = <<<ASS
[Script Info]
ScriptType: v4.00+
PlayResX: {$videoWidth}
PlayResY: {$videoHeight}
WrapStyle: 0
ScaledBorderAndShadow: yes

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
Style: Default,{$font},{$fontSize},{$color},{$color},{$outlineColor},{$backgroundColorAss},{$bold},{$italic},0,0,100,100,0,0,{$borderStyle},{$boxPadding},0,{$alignment},40,40,{$marginV},1

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text
ASS;

        $events = [];

        foreach ($segments as $seg) {
            if (! $highlightActiveWord || empty($seg['words'])) {
                $words = ! empty($seg['words'])
                    ? array_map(fn ($w) => $uppercase ? mb_strtoupper($w['word']) : $w['word'], $seg['words'])
                    : [$uppercase ? mb_strtoupper($seg['text']) : $seg['text']];

                $events[] = sprintf(
                    'Dialogue: 0,%s,%s,Default,,0,0,0,,%s%s',
                    $this->assTime($seg['start']),
                    $this->assTime($seg['end']),
                    $this->animationTag($animation, $seg['end'] - $seg['start']),
                    $this->escapeText($this->wrapDense($words))
                );

                continue;
            }

            foreach ($seg['words'] as $wordIndex => $activeWord) {
                $rendered = array_map(function ($w) use ($activeWord, $uppercase, $highlightColor) {
                    $word = $uppercase ? mb_strtoupper($w['word']) : $w['word'];

                    return $w === $activeWord ? "{\\c{$highlightColor}}{$word}{\\r}" : $word;
                }, $seg['words']);

                // Extend this word's display window up to the *next* word's start
                // (or the chunk's own end, for the last word) instead of stopping at
                // this word's own end. Any natural pause between two spoken words —
                // routine in real speech, and especially likely with source-caption
                // interpolation — would otherwise blank the caption out completely
                // in that gap, even though it's still the same sentence being said.
                $nextWord = $seg['words'][$wordIndex + 1] ?? null;
                $eventEnd = $nextWord ? $nextWord['start'] : $seg['end'];
                if ($eventEnd <= $activeWord['start']) {
                    $eventEnd = $activeWord['end'];
                }

                $events[] = sprintf(
                    'Dialogue: 0,%s,%s,Default,,0,0,0,,%s%s',
                    $this->assTime($activeWord['start']),
                    $this->assTime($eventEnd),
                    $this->animationTag($animation, $eventEnd - $activeWord['start']),
                    $this->escapeText($this->wrapDense($rendered))
                );
            }
        }

        return $header . "\n" . implode("\n", $events) . "\n";
    }

    /**
     * ASS override-tag prefix for a caption's entry effect. Fade/pop timings clamp
     * to a fraction of the event's own on-screen duration so a short single-word
     * cue (word-highlight mode can show one for well under 200ms) never spends its
     * whole lifetime animating in.
     */
    private function animationTag(string $animation, float $durationSeconds): string
    {
        if ($animation === 'none' || $animation === '') {
            return '';
        }

        $durationMs = max(1, (int) round($durationSeconds * 1000));

        return match ($animation) {
            'fade' => sprintf('{\\fad(%d,%d)}', min(120, intdiv($durationMs, 3)), min(80, intdiv($durationMs, 4))),
            'pop' => sprintf(
                '{\\t(0,%1$d,\\fscx115\\fscy115)\\t(%1$d,%2$d,\\fscx100\\fscy100)}',
                min(90, intdiv($durationMs, 3)),
                min(200, intdiv($durationMs, 2) + min(90, intdiv($durationMs, 3)))
            ),
            default => '',
        };
    }

    /**
     * Joins already-rendered word strings (which may carry ASS color tags) into one
     * line, or two via a forced \N break when the combined text is too dense for a
     * single readable line. Breaks at the word boundary closest to the midpoint by
     * visible character count (tags excluded from the count, since they're invisible
     * on screen) rather than a fixed word count, so the split stays balanced
     * regardless of word length.
     *
     * @param  string[]  $renderedWords
     */
    private function wrapDense(array $renderedWords): string
    {
        if (count($renderedWords) < 2) {
            return implode(' ', $renderedWords);
        }

        $lengths = array_map(fn ($w) => mb_strlen((string) preg_replace('/\{[^}]*\}/', '', $w)), $renderedWords);
        $total = array_sum($lengths) + count($renderedWords) - 1;

        if ($total <= self::MAX_LINE_CHARS) {
            return implode(' ', $renderedWords);
        }

        $target = $total / 2;
        $splitAt = 1;
        $acc = $lengths[0];

        for ($i = 1, $count = count($renderedWords); $i < $count; $i++) {
            $withNext = $acc + 1 + $lengths[$i];
            if (abs($withNext - $target) >= abs($acc - $target)) {
                break;
            }
            $acc = $withNext;
            $splitAt = $i + 1;
        }

        $splitAt = min(max($splitAt, 1), count($renderedWords) - 1);

        return implode(' ', array_slice($renderedWords, 0, $splitAt))
            . '\\N'
            . implode(' ', array_slice($renderedWords, $splitAt));
    }

    private function escapeText(string $text): string
    {
        return str_replace(["\r", "\n"], ['', '\\N'], $text);
    }

    private function srtTime(float $seconds): string
    {
        $whole = (int) floor($seconds);
        $h = intdiv($whole, 3600);
        $m = intdiv($whole % 3600, 60);
        $s = $whole % 60;
        $ms = (int) round(($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d,%03d', $h, $m, $s, $ms);
    }

    private function assTime(float $seconds): string
    {
        $whole = (int) floor($seconds);
        $h = intdiv($whole, 3600);
        $m = intdiv($whole % 3600, 60);
        $s = $whole % 60;
        $cs = (int) round(($seconds - floor($seconds)) * 100);

        return sprintf('%d:%02d:%02d.%02d', $h, $m, $s, $cs);
    }

    private function hexToAss(string $hex, float $opacity = 1.0): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            $hex = 'FFFFFF';
        }
        $r = substr($hex, 0, 2);
        $g = substr($hex, 2, 2);
        $b = substr($hex, 4, 2);

        // ASS alpha is inverted: 00 = fully opaque, FF = fully transparent.
        $alpha = (int) round((1 - max(0, min(1, $opacity))) * 255);
        $alphaHex = strtoupper(str_pad(dechex($alpha), 2, '0', STR_PAD_LEFT));

        return "&H{$alphaHex}{$b}{$g}{$r}&";
    }
}
