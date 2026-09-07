<?php

namespace Tests\Unit;

use App\Services\Video\LayerCompositionService;
use PHPUnit\Framework\TestCase;

/**
 * Rotation on image/logo layers — what makes a sticker look placed rather than
 * pasted. `rotate` must grow its output to fit the turned image (or the corners
 * are clipped), and `overlay` anchors that grown box by its top-left, so the
 * position has to be pulled back by half the growth on each axis or the sticker
 * visibly drifts up and to the left as the angle increases.
 */
class LayerCompositionImageRotationTest extends TestCase
{
    private string $imagePath;

    protected function setUp(): void
    {
        parent::setUp();

        // A real file, because buildImageLayer() measures the image to work out
        // how far the rotated box grows — there's no FFmpeg expression for
        // "where the untouched image's top-left used to be".
        $this->imagePath = tempnam(sys_get_temp_dir(), 'sticker_').'.png';
        $image = imagecreatetruecolor(200, 100);
        imagepng($image, $this->imagePath);
        imagedestroy($image);
    }

    protected function tearDown(): void
    {
        @unlink($this->imagePath);
        parent::tearDown();
    }

    private function build(array $layer): array
    {
        return (new LayerCompositionService)->buildGraph(
            [$layer],
            'scaled',
            1000,
            2000,
            10.0,
            fn () => $this->imagePath,
            1,
        );
    }

    private function layer(float $rotation): array
    {
        return [
            'id' => 's1', 'type' => 'image', 'z_index' => 1,
            'timing' => ['start' => 0, 'end' => 5],
            'x' => 0.25, 'y' => 0.25, 'width' => 0.5,
            'rotation' => $rotation,
            'props' => ['image_path' => 'stickers/star-white.png'],
        ];
    }

    public function test_no_rotation_leaves_the_graph_and_position_untouched(): void
    {
        $graph = $this->build($this->layer(0))['graph'];

        $this->assertEmpty(array_filter($graph, fn ($l) => str_contains($l, 'rotate=')));
        // Plain main_w*x, with no compensation term appended.
        $this->assertStringContainsString('overlay=main_w*0.25:main_h*0.25', end($graph));
    }

    public function test_a_quarter_turn_grows_the_box_to_the_swapped_dimensions(): void
    {
        // 500x250 after scale=1000*0.5:-1 on a 200x100 source; turned 90° that
        // becomes 250x500.
        $graph = $this->build($this->layer(90))['graph'];
        $rotate = current(array_filter($graph, fn ($l) => str_contains($l, 'rotate=')));

        $this->assertStringContainsString('ow=250:oh=500', $rotate);
        $this->assertStringContainsString('c=none', $rotate, 'the corners exposed by the turn must stay transparent');
    }

    public function test_position_is_pulled_back_by_half_the_growth_so_the_centre_holds(): void
    {
        $graph = $this->build($this->layer(90))['graph'];
        $overlay = end($graph);

        // 500 wide -> 250: shrinks by 250, so the left edge moves IN by 125.
        // 250 tall -> 500: grows by 250, so the top edge moves OUT by 125.
        $this->assertStringContainsString('overlay=main_w*0.25+125.00:main_h*0.25-125.00', $overlay);
    }

    public function test_rotation_is_skipped_when_the_image_cannot_be_measured(): void
    {
        // Without the source's real dimensions the compensation can't be
        // computed, and rotating anyway would place the sticker somewhere the
        // editor never showed it — better to render it straight.
        $service = new LayerCompositionService;
        $built = $service->buildGraph(
            [$this->layer(45)],
            'scaled',
            1000,
            2000,
            10.0,
            fn () => '/no/such/file.png',
            1,
        );

        $this->assertEmpty(array_filter($built['graph'], fn ($l) => str_contains($l, 'rotate=')));
        $this->assertStringContainsString('overlay=main_w*0.25:main_h*0.25', end($built['graph']));
    }
}
