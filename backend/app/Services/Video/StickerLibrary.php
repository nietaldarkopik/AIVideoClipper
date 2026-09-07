<?php

namespace App\Services\Video;

use Illuminate\Support\Facades\Storage;

/**
 * The built-in sticker set the editor's Stickers panel browses.
 *
 * The stickers are DRAWN, not shipped as binary assets: FFmpeg's overlay path
 * needs a real raster it can decode (it has no SVG decoder at all), and emoji
 * rendered through drawtext come out monochrome on most builds — so a bundled
 * vector or font-based set would either not render or not look like the picker
 * promised. Generating them with GD keeps the repository free of binaries,
 * guarantees the file on disk matches what the picker shows, and makes the set
 * trivially extendable by adding one entry to SHAPES.
 *
 * Each sticker is a flat shape with a dark outline — the outline is what keeps a
 * white sticker readable over pale footage, and matches how sticker sets in
 * short-form editors are drawn.
 */
class StickerLibrary
{
    private const DIRECTORY = 'stickers';

    /** Final PNG size. Big enough to overlay at any sane fraction of a 1080-wide canvas. */
    private const SIZE = 256;

    /**
     * Everything is rasterized at SIZE * this and scaled down, which is what
     * anti-aliases the edges — GD's polygon fill has no anti-aliasing of its own,
     * so drawn at final size these would come out visibly jagged.
     */
    private const SUPERSAMPLE = 4;

    private const OUTLINE = [17, 17, 17];

    /** @var array<string, array{label: string, fill: array{0:int,1:int,2:int}}> */
    private const COLORS = [
        'white' => ['label' => 'White', 'fill' => [255, 255, 255]],
        'yellow' => ['label' => 'Yellow', 'fill' => [255, 209, 0]],
        'red' => ['label' => 'Red', 'fill' => [255, 59, 92]],
    ];

    /** @var array<string, string> shape key => human label */
    private const SHAPES = [
        'arrow_right' => 'Arrow',
        'arrow_down' => 'Arrow down',
        'star' => 'Star',
        'heart' => 'Heart',
        'burst' => 'Burst',
        'speech_bubble' => 'Speech bubble',
        'play' => 'Play',
        'check' => 'Check',
        'cross' => 'Cross',
        'circle' => 'Circle',
    ];

    /**
     * Every sticker, generating any that aren't on disk yet.
     *
     * Generation is lazy rather than a migration or a seeder because the set is
     * derived entirely from the constants above: a deploy that adds a shape
     * should just start serving it, with no extra step to remember, and a
     * deleted file should heal itself. Drawing all of them takes well under a
     * second, and after the first call this is only an exists() check per file.
     *
     * @return list<array{path: string, name: string, shape: string, color: string}>
     */
    public function all(): array
    {
        $disk = Storage::disk('media');
        $stickers = [];

        foreach (self::SHAPES as $shape => $shapeLabel) {
            foreach (self::COLORS as $color => $colorMeta) {
                $path = self::DIRECTORY."/{$shape}-{$color}.png";

                if (! $disk->exists($path)) {
                    $disk->put($path, $this->render($shape, $colorMeta['fill']));
                }

                $stickers[] = [
                    'path' => $path,
                    'name' => "{$shapeLabel} ({$colorMeta['label']})",
                    'shape' => $shape,
                    'color' => $color,
                ];
            }
        }

        return $stickers;
    }

    /**
     * Draws one sticker and returns the PNG bytes. The shape is filled twice —
     * once slightly expanded in the outline colour, then in the fill colour —
     * which is a cheap way to get an even border without stroking a path (GD has
     * no path stroking, and thick imagesetthickness lines leave gaps at corners).
     */
    private function render(string $shape, array $fill): string
    {
        $size = self::SIZE * self::SUPERSAMPLE;
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        $outlineColor = imagecolorallocate($canvas, ...self::OUTLINE);
        $fillColor = imagecolorallocate($canvas, ...$fill);

        // Outline width as a fraction of the sticker; scaling the shape about
        // its centre is what makes it uniform on every edge.
        $this->draw($canvas, $shape, $size, 1.0, $outlineColor);
        $this->draw($canvas, $shape, $size, 0.90, $fillColor);

        $out = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $canvas, 0, 0, 0, 0, self::SIZE, self::SIZE, $size, $size);
        imagedestroy($canvas);

        ob_start();
        imagepng($out);
        $png = (string) ob_get_clean();
        imagedestroy($out);

        return $png;
    }

    /**
     * @param  \GdImage  $canvas
     * @param  float  $scale  shrinks the shape about the canvas centre, giving the outline pass its extra width
     */
    private function draw($canvas, string $shape, int $size, float $scale, int $color): void
    {
        if ($shape === 'circle') {
            $diameter = (int) round($size * 0.86 * $scale);
            imagefilledellipse($canvas, intdiv($size, 2), intdiv($size, 2), $diameter, $diameter, $color);

            return;
        }

        $points = $this->points($shape);
        $flat = [];
        foreach ($points as [$px, $py]) {
            // Normalized 0..1 shape coordinates -> canvas pixels, scaled about
            // the centre (0.5, 0.5).
            $flat[] = (int) round(($px - 0.5) * $scale * $size + $size / 2);
            $flat[] = (int) round(($py - 0.5) * $scale * $size + $size / 2);
        }

        imagefilledpolygon($canvas, $flat, $color);
    }

    /**
     * Shape outlines as normalized 0..1 points, wound consistently so GD's
     * even-odd polygon fill produces a solid body.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function points(string $shape): array
    {
        return match ($shape) {
            'arrow_right' => [
                [0.05, 0.34], [0.55, 0.34], [0.55, 0.12], [0.95, 0.50],
                [0.55, 0.88], [0.55, 0.66], [0.05, 0.66],
            ],
            'arrow_down' => [
                [0.34, 0.05], [0.66, 0.05], [0.66, 0.55], [0.88, 0.55],
                [0.50, 0.95], [0.12, 0.55], [0.34, 0.55],
            ],
            'play' => [[0.20, 0.08], [0.92, 0.50], [0.20, 0.92]],
            'check' => [
                [0.10, 0.52], [0.22, 0.40], [0.40, 0.58], [0.78, 0.18],
                [0.90, 0.30], [0.40, 0.82],
            ],
            'cross' => $this->crossPoints(),
            'speech_bubble' => $this->speechBubblePoints(),
            'star' => $this->radialStar(5, 0.48, 0.20, -M_PI / 2),
            'burst' => $this->radialStar(12, 0.48, 0.32, -M_PI / 2),
            'heart' => $this->heartPoints(),
            default => [[0.1, 0.1], [0.9, 0.1], [0.9, 0.9], [0.1, 0.9]],
        };
    }

    /**
     * An n-pointed star: alternating outer and inner vertices around the centre.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function radialStar(int $points, float $outer, float $inner, float $startAngle): array
    {
        $vertices = [];
        $step = M_PI / $points;

        for ($i = 0; $i < $points * 2; $i++) {
            $radius = $i % 2 === 0 ? $outer : $inner;
            $angle = $startAngle + $i * $step;
            $vertices[] = [0.5 + $radius * cos($angle), 0.5 + $radius * sin($angle)];
        }

        return $vertices;
    }

    /**
     * The classic parametric heart (x = 16sin³t, y = 13cos t − 5cos 2t − 2cos 3t
     * − cos 4t), sampled into a polygon and normalized into the 0..1 box. Sampled
     * rather than drawn with arcs because GD can only fill a polygon, and at 4x
     * supersampling 72 samples are already smoother than the final pixels.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function heartPoints(): array
    {
        $raw = [];
        for ($i = 0; $i < 72; $i++) {
            $t = $i / 72 * 2 * M_PI;
            $raw[] = [
                16 * sin($t) ** 3,
                -(13 * cos($t) - 5 * cos(2 * $t) - 2 * cos(3 * $t) - cos(4 * $t)),
            ];
        }

        // The curve's own extent is roughly x ±16, y ±17 — normalize off the
        // actual sampled bounds so the shape fills the sticker regardless.
        $xs = array_column($raw, 0);
        $ys = array_column($raw, 1);
        $minX = min($xs);
        $minY = min($ys);
        $spanX = max($xs) - $minX;
        $spanY = max($ys) - $minY;
        $span = max($spanX, $spanY);

        $points = [];
        foreach ($raw as [$px, $py]) {
            // Centred in the box, uniformly scaled so it isn't stretched.
            $points[] = [
                0.5 + ($px - $minX - $spanX / 2) / $span * 0.94,
                0.5 + ($py - $minY - $spanY / 2) / $span * 0.94,
            ];
        }

        return $points;
    }

    /**
     * An X, built as a plus sign turned 45°. Writing the twelve vertices of an X
     * out directly means every one of them is an awkward combination of the arm
     * length and its thickness projected onto a diagonal — easy to get subtly
     * wrong, and a self-intersecting result fills into a mess under GD's
     * even-odd rule. A plus is trivial to state exactly, and rotating it is
     * exact, so the shape is correct by construction.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function crossPoints(): array
    {
        $t = 0.13;  // arm half-thickness
        $l = 0.46;  // arm half-length

        $plus = [
            [-$t, -$l], [$t, -$l], [$t, -$t], [$l, -$t], [$l, $t], [$t, $t],
            [$t, $l], [-$t, $l], [-$t, $t], [-$l, $t], [-$l, -$t], [-$t, -$t],
        ];

        $angle = M_PI / 4;
        $points = [];
        foreach ($plus as [$px, $py]) {
            $points[] = [
                0.5 + $px * cos($angle) - $py * sin($angle),
                0.5 + $px * sin($angle) + $py * cos($angle),
            ];
        }

        return $points;
    }

    /**
     * A rounded-ish speech bubble with a tail at the bottom-left. The body's
     * corners are chamfered rather than truly rounded — at this size, after
     * downsampling, the two are indistinguishable.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function speechBubblePoints(): array
    {
        $c = 0.10; // corner chamfer
        $top = 0.10;
        $bottom = 0.68;

        return [
            [0.06 + $c, $top], [0.94 - $c, $top], [0.94, $top + $c],
            [0.94, $bottom - $c], [0.94 - $c, $bottom],
            [0.42, $bottom], [0.30, 0.94], [0.28, $bottom],
            [0.06 + $c, $bottom], [0.06, $bottom - $c], [0.06, $top + $c],
        ];
    }
}
