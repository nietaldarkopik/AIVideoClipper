<?php

namespace App\Services\Video;

class SrtParser
{
    /**
     * @return array<int, array{start: float, end: float, text: string, speaker: string}>
     */
    public static function parse(string $srtContent): array
    {
        $srtContent = str_replace("\r\n", "\n", $srtContent);
        $blocks = preg_split('/\n\n+/', trim($srtContent));
        $rawBlocks = [];

        foreach ($blocks as $block) {
            $lines = explode("\n", trim($block));
            if (count($lines) < 2) {
                continue;
            }

            // First line is a numeric index, second is "start --> end"; skip the index.
            $timeLine = str_contains($lines[0], '-->') ? $lines[0] : ($lines[1] ?? '');
            if (! preg_match('/(\d{2}:\d{2}:\d{2}[,.]\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2}[,.]\d{3})/', $timeLine, $m)) {
                continue;
            }

            $textLines = str_contains($lines[0], '-->') ? array_slice($lines, 1) : array_slice($lines, 2);
            $textLines = array_map(fn (string $l) => trim((string) preg_replace('/<[^>]+>/', '', $l)), $textLines);
            $textLines = array_values(array_filter($textLines, fn (string $l) => $l !== ''));

            if (empty($textLines)) {
                continue;
            }

            $rawBlocks[] = [
                'start' => self::toSeconds($m[1]),
                'end' => self::toSeconds($m[2]),
                'lines' => $textLines,
            ];
        }

        return self::dedupeRollingLines($rawBlocks);
    }

    /**
     * YouTube auto-captions are literal 2-line "rolling" cues: a block's first line
     * is a verbatim repeat of the *previous* block's last line (the on-screen
     * scroll-up), often followed by a ~10ms "blank second line" block that is
     * itself entirely a repeat of what's already been shown. Burned in as-is, every
     * rolling line reads twice in a row (or, across one of those blank-transition
     * blocks, three times). Deduping by matching a block's leading line against the
     * *exact* previous line (rather than fuzzy word overlap) handles this precisely
     * — including single-word lines, which a word-overlap heuristic can't safely
     * catch without also risking false positives on short coincidental repeats.
     * Normal, non-rolling SRTs never repeat a line verbatim block-to-block, so this
     * is a no-op for them.
     *
     * @param  array<int, array{start: float, end: float, lines: string[]}>  $blocks
     * @return array<int, array{start: float, end: float, text: string, speaker: string}>
     */
    private static function dedupeRollingLines(array $blocks): array
    {
        $normalize = fn (string $l) => preg_replace('/[^\p{L}\p{N}\s]+/u', '', mb_strtolower(trim($l)));
        $result = [];
        $prevLastLine = null;

        foreach ($blocks as $b) {
            $lines = $b['lines'];
            $originalCount = count($lines);
            $newPrevLastLine = $normalize(end($lines));

            if ($prevLastLine !== null && $normalize($lines[0]) === $prevLastLine) {
                array_shift($lines);
            }

            if (empty($lines)) {
                $prevLastLine = $newPrevLastLine;

                continue;
            }

            $droppedFraction = ($originalCount - count($lines)) / $originalCount;
            $result[] = [
                'start' => round($b['start'] + ($b['end'] - $b['start']) * $droppedFraction, 2),
                'end' => $b['end'],
                'text' => implode(' ', $lines),
                'speaker' => 'A',
            ];
            $prevLastLine = $newPrevLastLine;
        }

        return $result;
    }

    private static function toSeconds(string $timecode): float
    {
        $timecode = str_replace(',', '.', $timecode);
        [$h, $m, $s] = explode(':', $timecode);

        return round(((int) $h) * 3600 + ((int) $m) * 60 + (float) $s, 2);
    }
}
