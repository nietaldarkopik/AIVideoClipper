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
        $segments = [];

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
            $text = trim(preg_replace('/<[^>]+>/', '', implode(' ', $textLines)));
            if ($text === '') {
                continue;
            }

            $segments[] = [
                'start' => self::toSeconds($m[1]),
                'end' => self::toSeconds($m[2]),
                'text' => $text,
                'speaker' => 'A',
            ];
        }

        return $segments;
    }

    private static function toSeconds(string $timecode): float
    {
        $timecode = str_replace(',', '.', $timecode);
        [$h, $m, $s] = explode(':', $timecode);

        return round(((int) $h) * 3600 + ((int) $m) * 60 + (float) $s, 2);
    }
}
