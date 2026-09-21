<?php

namespace Tests\Feature;

use App\Services\Video\FFmpegService;
use Tests\TestCase;

/**
 * FFmpegService::extractWithoutSilence()'s $transitions param — the only
 * automated (not hand-verified) coverage of it, since every other FFmpeg-
 * executing method in this class is verified by hand against a real render
 * rather than run in the suite (see ClipSpeedVolumeTest's docblock). This one
 * earns an exception: developing it surfaced a real bug — a `concat` filter's
 * output pad does not inherit its inputs' time base, so a hard-cut boundary
 * followed later by a crossfade made ffmpeg reject the filtergraph outright
 * ("First input link main timebase ... do not match") — and that failure mode
 * only shows up by actually invoking ffmpeg, not by inspecting PHP data.
 * Kept fast: tiny resolution, sub-3-second sources, superfast preset.
 */
class ClipTransitionRenderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/clip_transition_test_'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * Two solid-color segments back to back, so a render either succeeds or
     * ffmpeg rejects the filtergraph — no frame-content assertion needed here
     * (the crossfade actually blending correctly was confirmed by hand,
     * extracting frames around the boundary); this guards structure and
     * timing, which is what a filtergraph rejection or an arithmetic mistake
     * would break.
     */
    private function twoToneSource(): string
    {
        $path = $this->dir.'/two_tone.mp4';
        $this->ffmpeg([
            '-f', 'lavfi', '-i', 'color=c=blue:s=64x64:rate=10:d=3',
            '-f', 'lavfi', '-i', 'color=c=red:s=64x64:rate=10:d=3',
            '-f', 'lavfi', '-i', 'sine=frequency=300:duration=6',
            '-filter_complex', '[0:v][1:v]concat=n=2:v=1:a=0[v]',
            '-map', '[v]', '-map', '2:a',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-c:a', 'aac', $path,
        ]);

        return $path;
    }

    private function threeToneSource(): string
    {
        $path = $this->dir.'/three_tone.mp4';
        $this->ffmpeg([
            '-f', 'lavfi', '-i', 'color=c=blue:s=64x64:rate=10:d=2',
            '-f', 'lavfi', '-i', 'color=c=green:s=64x64:rate=10:d=2',
            '-f', 'lavfi', '-i', 'color=c=red:s=64x64:rate=10:d=2',
            '-f', 'lavfi', '-i', 'sine=frequency=300:duration=6',
            '-filter_complex', '[0:v][1:v][2:v]concat=n=3:v=1:a=0[v]',
            '-map', '[v]', '-map', '3:a',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-c:a', 'aac', $path,
        ]);

        return $path;
    }

    private function ffmpeg(array $args): void
    {
        $cmd = array_merge(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y'], $args);
        exec(implode(' ', array_map('escapeshellarg', $cmd)), $output, $exitCode);
        if ($exitCode !== 0) {
            $this->markTestSkipped('ffmpeg is not available or failed to build the test fixture: '.implode("\n", $output));
        }
    }

    public function test_no_transitions_leaves_duration_unchanged(): void
    {
        $svc = new FFmpegService;
        $out = $this->dir.'/out.mp4';

        $svc->extractWithoutSilence($this->twoToneSource(), $out, 0.0, [
            ['start' => 0.0, 'end' => 3.0],
            ['start' => 3.0, 'end' => 6.0],
        ]);

        $this->assertEqualsWithDelta(6.0, $svc->probe($out)['duration'], 0.2);
    }

    public function test_a_crossfade_shortens_the_output_by_its_own_duration(): void
    {
        $svc = new FFmpegService;
        $out = $this->dir.'/out.mp4';

        $svc->extractWithoutSilence($this->twoToneSource(), $out, 0.0, [
            ['start' => 0.0, 'end' => 3.0],
            ['start' => 3.0, 'end' => 6.0],
        ], [1 => ['type' => 'fade', 'duration' => 1.0]]);

        // 3 + 3 - 1 (the two segments overlap for the crossfade's duration).
        $this->assertEqualsWithDelta(5.0, $svc->probe($out)['duration'], 0.2);
    }

    /**
     * The regression case: a hard-cut boundary (no transition entry) followed
     * by a crossfaded one. Before the fps= re-normalization fix, ffmpeg
     * rejected this outright — extractWithoutSilence() would throw.
     */
    public function test_a_hard_cut_before_a_later_crossfade_does_not_throw(): void
    {
        $svc = new FFmpegService;
        $out = $this->dir.'/out.mp4';

        $svc->extractWithoutSilence($this->threeToneSource(), $out, 0.0, [
            ['start' => 0.0, 'end' => 2.0],
            ['start' => 2.0, 'end' => 4.0],
            ['start' => 4.0, 'end' => 6.0],
        ], [2 => ['type' => 'dissolve', 'duration' => 0.6]]);

        // 2 + 2 + 2 - 0.6 — only the second boundary's crossfade shortens it;
        // the first (hard-cut) boundary contributes its full 2s.
        $this->assertEqualsWithDelta(5.4, $svc->probe($out)['duration'], 0.2);
    }

    public function test_a_transition_duration_longer_than_either_neighbor_is_clamped(): void
    {
        $svc = new FFmpegService;
        $out = $this->dir.'/out.mp4';

        // Requesting a 5s crossfade between two 3s segments must clamp down to
        // something that still leaves a positive-length output, not send the
        // offset negative or fail.
        $svc->extractWithoutSilence($this->twoToneSource(), $out, 0.0, [
            ['start' => 0.0, 'end' => 3.0],
            ['start' => 3.0, 'end' => 6.0],
        ], [1 => ['type' => 'fade', 'duration' => 5.0]]);

        // Clamped to the shorter neighbor's full length (3s) — the crossfade
        // covers the first segment entirely rather than reaching past it, so
        // the output is exactly that segment's length, never less and never
        // the naive (unclamped) 6 - 5 = 1.
        $duration = $svc->probe($out)['duration'];
        $this->assertEqualsWithDelta(3.0, $duration, 0.2);
        $this->assertLessThan(6.0, $duration);
    }
}
