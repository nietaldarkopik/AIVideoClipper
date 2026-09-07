<?php

namespace Tests\Unit;

use App\Services\Video\LayerCompositionService;
use PHPUnit\Framework\TestCase;

/**
 * The 'effect' and 'filter' layer types — the render side of the editor's
 * Effects and Filters panels. Both go through buildColorLayer(); these assert
 * the filtergraph it produces, in particular that the timing gate lands on
 * EVERY filter in a multi-filter chain (a gate on only the last one would leave
 * the rest applied for the whole clip).
 */
class LayerCompositionColorLayerTest extends TestCase
{
    private function build(array $layer, float $duration = 10.0): array
    {
        return (new LayerCompositionService)->buildGraph(
            [$layer],
            'scaled',
            1080,
            1920,
            $duration,
            fn (string $p) => $p,
            1,
        );
    }

    /**
     * FFmpegService calls buildGraph() twice for one render — once for the layers
     * below the caption burn-in (which is where every effect/filter goes) and once
     * for those above it. Pad names come from each layer's index, so without a
     * per-call prefix both calls emit a pad literally named "layer0", ffmpeg
     * accepts the duplicate, and the final `-map [layer0]` silently maps the FIRST
     * one — dropping every layer the second call built. Reachable on any clip that
     * combines an effect or filter with a text/image overlay.
     */
    public function test_a_label_prefix_keeps_two_groups_in_one_graph_from_colliding(): void
    {
        $service = new LayerCompositionService;
        $effect = [
            'id' => 'e1', 'type' => 'effect', 'z_index' => -50,
            'timing' => ['start' => 0, 'end' => 2],
            'props' => ['effect' => 'contrast', 'intensity' => 1],
        ];
        $rect = [
            'id' => 'r1', 'type' => 'rect', 'z_index' => 1,
            'timing' => ['start' => 0, 'end' => 2],
            'props' => ['color' => '#000000'],
        ];

        $behind = $service->buildGraph([$effect], 'scaled', 1080, 1920, 10.0, fn ($p) => $p, 1, 'behind');
        $above = $service->buildGraph([$rect], $behind['videoLabel'], 1080, 1920, 10.0, fn ($p) => $p, 1, 'above');

        $this->assertSame('layerbehind0', $behind['videoLabel']);
        $this->assertSame('layerabove0', $above['videoLabel']);
        $this->assertNotSame($behind['videoLabel'], $above['videoLabel']);
    }

    public function test_a_timed_blur_effect_is_gated_to_its_own_window(): void
    {
        $built = $this->build([
            'id' => 'e1', 'type' => 'effect', 'z_index' => -50,
            'timing' => ['start' => 2, 'end' => 5],
            'props' => ['effect' => 'blur', 'intensity' => 0.5],
        ]);

        $this->assertCount(1, $built['graph']);
        $this->assertSame("[scaled]gblur=sigma=10.500:enable='between(t,2,5)'[layer0]", $built['graph'][0]);
        // Colour work never adds an input or an audio pad.
        $this->assertSame([], $built['inputArgs']);
        $this->assertSame([], $built['audioLabels']);
        $this->assertSame('layer0', $built['videoLabel']);
    }

    public function test_every_filter_in_a_multi_filter_preset_carries_the_timing_gate(): void
    {
        $built = $this->build([
            'id' => 'f1', 'type' => 'filter', 'z_index' => -100,
            'timing' => ['start' => 0, 'end' => null],
            'props' => ['preset' => 'warm', 'intensity' => 1],
        ], 8.0);

        $line = $built['graph'][0];
        $this->assertSame(2, substr_count($line, "enable='between(t,0,8)'"), 'both colorbalance and eq must be gated');
        $this->assertStringContainsString('colorbalance=rs=0.150', $line);
        $this->assertStringContainsString('eq=saturation=1.150', $line);
    }

    public function test_a_null_end_spans_the_whole_clip(): void
    {
        $built = $this->build([
            'id' => 'f1', 'type' => 'filter', 'z_index' => -100,
            'timing' => ['start' => 0, 'end' => null],
            'props' => ['preset' => 'bw', 'intensity' => 1],
        ], 12.5);

        $this->assertStringContainsString("enable='between(t,0,12.5)'", $built['graph'][0]);
    }

    public function test_intensity_scales_the_look_and_zero_renders_nothing(): void
    {
        $weak = $this->build([
            'id' => 'e1', 'type' => 'effect', 'z_index' => -50,
            'timing' => ['start' => 0, 'end' => 3],
            'props' => ['effect' => 'grayscale', 'intensity' => 0.25],
        ]);
        // hue=s= is saturation RETAINED, so a quarter-strength grayscale keeps 0.75.
        $this->assertStringContainsString('hue=s=0.750', $weak['graph'][0]);

        $off = $this->build([
            'id' => 'e2', 'type' => 'effect', 'z_index' => -50,
            'timing' => ['start' => 0, 'end' => 3],
            'props' => ['effect' => 'grayscale', 'intensity' => 0],
        ]);
        $this->assertSame([], $off['graph']);
        // The video pad must pass straight through, or the next layer would be
        // chained onto a label that was never produced.
        $this->assertSame('scaled', $off['videoLabel']);
    }

    public function test_the_normal_preset_and_an_unknown_name_leave_the_frame_untouched(): void
    {
        foreach (['normal', 'not-a-real-preset'] as $preset) {
            $built = $this->build([
                'id' => 'f1', 'type' => 'filter', 'z_index' => -100,
                'timing' => ['start' => 0, 'end' => null],
                'props' => ['preset' => $preset],
            ]);

            $this->assertSame([], $built['graph'], "preset '{$preset}' should be a no-op");
            $this->assertSame('scaled', $built['videoLabel']);
        }
    }
}
