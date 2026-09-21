<?php

namespace App\Services\Video;

/**
 * Detects long silent gaps in a clip's audio and removes them before crop/caption
 * rendering — a "jump cut" edit so a rendered clip doesn't sit on dead air. Runs as
 * a pre-pass ahead of FFmpegService::renderClip(): the caller gets silence
 * intervals from FFmpegService::detectSilence(), turns them into "keep" intervals
 * here, extracts a silence-free temp source via FFmpegService::extractWithoutSilence(),
 * then must remap every existing crop-keyframe and caption timestamp from the
 * original clip timeline onto the new, shorter one using remapTimestamp() /
 * remapWords() before handing them to renderClip() — see RenderClipJob.
 */
class SilenceTrimmer
{
    // A gap has to be at least this long before it's worth a jump cut — shorter
    // gaps are normal speech pauses (breath, punctuation), not dead air, and
    // cutting them makes delivery sound clipped/robotic rather than tighter.
    public const MIN_SILENCE_SECONDS = 0.6;

    // dB threshold below which audio counts as "silence" for detection purposes.
    public const NOISE_THRESHOLD_DB = -35;

    // Kept on each side of a cut so a word's onset/decay isn't clipped by the edit.
    private const PADDING_SECONDS = 0.15;

    /**
     * @param  list<array{start: float, end: float}>  $silences  clip-relative, from FFmpegService::detectSilence()
     * @return list<array{start: float, end: float}>  segments to KEEP, clip-relative, always non-empty and sorted
     */
    public function computeKeepIntervals(array $silences, float $duration): array
    {
        if (empty($silences)) {
            return [['start' => 0.0, 'end' => $duration]];
        }

        usort($silences, fn ($a, $b) => $a['start'] <=> $b['start']);

        $keep = [];
        $cursor = 0.0;

        foreach ($silences as $sil) {
            // Padding can eat a short gap entirely — that just means it wasn't
            // worth cutting once the safety margin is applied, skip it.
            $cutStart = min($sil['start'] + self::PADDING_SECONDS, $duration);
            $cutEnd = max($sil['end'] - self::PADDING_SECONDS, 0.0);

            if ($cutEnd <= $cutStart || $cutStart <= $cursor) {
                continue;
            }

            $keep[] = ['start' => $cursor, 'end' => $cutStart];
            $cursor = $cutEnd;
        }

        if ($cursor < $duration) {
            $keep[] = ['start' => $cursor, 'end' => $duration];
        }

        return $keep ?: [['start' => 0.0, 'end' => $duration]];
    }

    /**
     * @param  list<array{start: float, end: float}>  $keepIntervals
     */
    public function totalDuration(array $keepIntervals): float
    {
        return array_sum(array_map(fn ($s) => $s['end'] - $s['start'], $keepIntervals));
    }

    /**
     * True when $keepIntervals actually removes something (as opposed to being the
     * trivial single [0, duration] interval detectSilence() returns when nothing
     * was cut) — callers use this to skip the extraction pass entirely when there's
     * nothing to do.
     *
     * @param  list<array{start: float, end: float}>  $keepIntervals
     */
    public function hasCuts(array $keepIntervals, float $duration): bool
    {
        return count($keepIntervals) > 1
            || $keepIntervals[0]['start'] > 0.0
            || $keepIntervals[0]['end'] < $duration - 0.01;
    }

    /**
     * Maps a timestamp on the original (pre-cut) clip timeline onto the trimmed
     * timeline built by concatenating $keepIntervals in order. A timestamp inside a
     * removed gap clamps to the start of the next kept interval.
     *
     * @param  list<array{start: float, end: float}>  $keepIntervals
     */
    public function remapTimestamp(float $t, array $keepIntervals): float
    {
        $cumulative = 0.0;

        foreach ($keepIntervals as $seg) {
            if ($t < $seg['start']) {
                return $cumulative;
            }
            if ($t <= $seg['end']) {
                return $cumulative + ($t - $seg['start']);
            }
            $cumulative += $seg['end'] - $seg['start'];
        }

        return $cumulative;
    }

    /**
     * True when a [start,end] span (e.g. a spoken word, or a crop keyframe sampled
     * as a zero-length point) falls entirely inside a removed gap.
     *
     * @param  list<array{start: float, end: float}>  $keepIntervals
     */
    public function isFullyRemoved(float $start, float $end, array $keepIntervals): bool
    {
        foreach ($keepIntervals as $seg) {
            if ($start >= $seg['start'] - 0.001 && $end <= $seg['end'] + 0.001) {
                return false;
            }
        }

        return true;
    }

    /**
     * Filters and remaps transcript words (absolute source timestamps) onto the
     * post-silence-removal clip timeline, ready to hand to
     * SubtitleService::buildClipSegments() with clipStart=0. Words that fall
     * entirely inside a removed gap are dropped instead of remapped.
     *
     * @param  array<int, array{word: string, start: float, end: float}>  $words  absolute
     * @param  list<array{start: float, end: float}>  $keepIntervals  clip-relative
     * @return array<int, array{word: string, start: float, end: float}>  absolute-shaped but 0-based on the new timeline
     */
    public function remapWords(array $words, float $clipStart, float $clipEnd, array $keepIntervals): array
    {
        $out = [];

        foreach ($words as $w) {
            if ($w['end'] <= $clipStart || $w['start'] >= $clipEnd) {
                continue;
            }

            $relStart = max(0.0, $w['start'] - $clipStart);
            $relEnd = min($clipEnd - $clipStart, $w['end'] - $clipStart);

            if ($this->isFullyRemoved($relStart, $relEnd, $keepIntervals)) {
                continue;
            }

            $mappedStart = $this->remapTimestamp($relStart, $keepIntervals);
            $mappedEnd = max($mappedStart + 0.01, $this->remapTimestamp($relEnd, $keepIntervals));

            $out[] = ['word' => $w['word'], 'start' => $mappedStart, 'end' => $mappedEnd];
        }

        return $out;
    }

    /**
     * Remaps crop keyframes (point samples, 'time' clip-relative) onto the
     * post-silence-removal timeline, dropping any keyframe whose original time
     * falls inside a removed gap and collapsing any resulting same-time duplicates
     * (keeping the first) so times stay strictly increasing — FFmpegService's
     * crop-expression builder assumes that invariant.
     *
     * @param  array<int, array{time: float, x: float, y: float, width: float, height: float}>  $keyframes  clip-relative
     * @param  list<array{start: float, end: float}>  $keepIntervals
     * @return array<int, array{time: float, x: float, y: float, width: float, height: float}>
     */
    public function remapKeyframes(array $keyframes, array $keepIntervals): array
    {
        $out = [];
        $lastTime = null;

        foreach ($keyframes as $k) {
            if ($this->isFullyRemoved((float) $k['time'], (float) $k['time'], $keepIntervals)) {
                continue;
            }

            $mapped = $this->remapTimestamp((float) $k['time'], $keepIntervals);

            if ($lastTime !== null && $mapped <= $lastTime) {
                continue;
            }

            $out[] = [...$k, 'time' => $mapped];
            $lastTime = $mapped;
        }

        return $out;
    }
}
