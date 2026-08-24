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
        $outlineColor = $this->hexToAss($config['stroke_color'] ?? '#000000');
        $backColor = $this->hexToAss($config['background'] ?? '#000000', (float) ($config['background_opacity'] ?? 0));
        $outline = (int) ($config['stroke_width'] ?? 3);
        $bold = ! empty($config['bold']) ? -1 : 0;
        $alignment = match ($config['position'] ?? 'bottom') {
            'top' => 8,
            'center', 'middle' => 5,
            default => 2,
        };
        $marginV = (int) ($config['margin_v'] ?? round($videoHeight * 0.08));
        $uppercase = ! empty($config['uppercase']);
        $highlightActiveWord = ! empty($config['highlight_active_word']);

        $header = <<<ASS
[Script Info]
ScriptType: v4.00+
PlayResX: {$videoWidth}
PlayResY: {$videoHeight}
WrapStyle: 0
ScaledBorderAndShadow: yes

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
Style: Default,{$font},{$fontSize},{$color},{$color},{$outlineColor},{$backColor},{$bold},0,0,0,100,100,0,0,1,{$outline},0,{$alignment},40,40,{$marginV},1

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text
ASS;

        $events = [];

        foreach ($segments as $seg) {
            $text = $uppercase ? mb_strtoupper($seg['text']) : $seg['text'];

            if (! $highlightActiveWord || empty($seg['words'])) {
                $events[] = sprintf(
                    'Dialogue: 0,%s,%s,Default,,0,0,0,,%s',
                    $this->assTime($seg['start']),
                    $this->assTime($seg['end']),
                    $this->escapeText($text)
                );

                continue;
            }

            foreach ($seg['words'] as $activeWord) {
                $rendered = implode(' ', array_map(function ($w) use ($activeWord, $uppercase, $highlightColor) {
                    $word = $uppercase ? mb_strtoupper($w['word']) : $w['word'];

                    return $w === $activeWord ? "{\\c{$highlightColor}}{$word}{\\r}" : $word;
                }, $seg['words']));

                $events[] = sprintf(
                    'Dialogue: 0,%s,%s,Default,,0,0,0,,%s',
                    $this->assTime($activeWord['start']),
                    $this->assTime($activeWord['end']),
                    $this->escapeText($rendered)
                );
            }
        }

        return $header . "\n" . implode("\n", $events) . "\n";
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
