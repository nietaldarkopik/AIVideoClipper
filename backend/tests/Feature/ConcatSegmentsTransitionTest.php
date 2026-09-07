<?php

namespace Tests\Feature;

use App\Services\Video\FFmpegService;
use Tests\TestCase;

/**
 * FFmpegService::concatSegments()'s extended $transitions param — the
 * per-boundary sibling of extractWithoutSilence()'s own, added so multiple
 * independently-rendered additional video clips (RenderClipJob's
 * renderAdditionalVideoClips()) can each have their own transition into them,
 * generalizing the mechanism composeIntroOutro() already used with one shared
 * setting. Same "real ffmpeg, not just PHP data" rationale as
 * ClipTransitionRenderTest: this exact shape (a hard-cut boundary followed by
 * a later crossfade) is what surfaced a real timebase-mismatch bug in
 * extractWithoutSilence() during development, and concatSegments() needed the
 * identical fps= re-normalization fix to avoid the same failure.
 */
class ConcatSegmentsTransitionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/concat_transition_test_'.uniqid();
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

    private function ffmpeg(array $args): void
    {
        $cmd = array_merge(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y'], $args);
        exec(implode(' ', array_map('escapeshellarg', $cmd)), $output, $exitCode);
        if ($exitCode !== 0) {
            $this->markTestSkipped('ffmpeg is not available or failed to build a test fixture: '.implode("\n", $output));
        }
    }

    /**
     * @return list<string> three independently-produced 2s segment files
     */
    private function threeSegments(): array
    {
        $paths = [];
        foreach (['blue', 'green', 'red'] as $color) {
            $path = $this->dir."/seg_{$color}.mp4";
            $this->ffmpeg([
                '-f', 'lavfi', '-i', "color=c={$color}:s=64x64:rate=10:d=2",
                '-f', 'lavfi', '-i', 'sine=frequency=300:duration=2',
                '-c:v', 'libx264', '-preset', 'ultrafast', '-c:a', 'aac', $path,
            ]);
            $paths[] = $path;
        }

        return $paths;
    }

    public function test_no_transitions_is_a_hard_cut_with_unchanged_total_duration(): void
    {
        $svc = new FFmpegService;
        $out = $this->dir.'/out.mp4';

        $svc->concatSegments($this->threeSegments(), $out, 64, 64);

        $this->assertEqualsWithDelta(6.0, $svc->probe($out)['duration'], 0.2);
    }

    public function test_the_legacy_single_transition_param_still_applies_to_every_boundary(): void
    {
        // composeIntroOutro()'s existing call shape — must keep working exactly
        // as it did before $transitions existed.
        $svc = new FFmpegService;
        $out = $this->dir.'/out.mp4';

        $svc->concatSegments($this->threeSegments(), $out, 64, 64, ['type' => 'fade', 'duration' => 0.5]);

        // Both boundaries crossfade: 2+2+2 - 0.5 - 0.5 = 5.
        $this->assertEqualsWithDelta(5.0, $svc->probe($out)['duration'], 0.2);
    }

    /**
     * The regression case: a hard-cut boundary (no entry) followed by a
     * crossfaded one. Before concatSegments() got the same fps=
     * re-normalization fix as extractWithoutSilence(), this shape made ffmpeg
     * reject the filtergraph outright.
     */
    public function test_per_boundary_transitions_mix_hard_cuts_and_crossfades_without_throwing(): void
    {
        $svc = new FFmpegService;
        $out = $this->dir.'/out.mp4';

        $svc->concatSegments($this->threeSegments(), $out, 64, 64, null, [
            2 => ['type' => 'dissolve', 'duration' => 0.6],
        ]);

        // Only the second boundary crossfades: 2+2+2 - 0.6 = 5.4.
        $this->assertEqualsWithDelta(5.4, $svc->probe($out)['duration'], 0.2);
    }

    public function test_per_boundary_transitions_take_precedence_over_the_legacy_param(): void
    {
        $svc = new FFmpegService;
        $out = $this->dir.'/out.mp4';

        // If $transitions were ignored in favor of $transition, both boundaries
        // would fade by 0.5s each (duration 5.0) instead of just one by 0.6s.
        $svc->concatSegments($this->threeSegments(), $out, 64, 64,
            ['type' => 'fade', 'duration' => 0.5],
            [2 => ['type' => 'dissolve', 'duration' => 0.6]]
        );

        $this->assertEqualsWithDelta(5.4, $svc->probe($out)['duration'], 0.2);
    }
}
