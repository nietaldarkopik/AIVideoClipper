<?php

namespace App\Services\Video;

/**
 * Prompt and caption styling for cartoon explainer videos, kept in one place so scene
 * images stay visually consistent with each other.
 */
class CartoonVideoStyle
{
    public const DEFAULT = 'flat_cartoon';

    private const STYLES = [
        'flat_cartoon' => 'Flat 2D vector cartoon illustration, bold clean outlines, bright saturated colors, '
            . 'simple shapes, expressive characters, plain uncluttered background',
    ];

    public static function exists(string $style): bool
    {
        return isset(self::STYLES[$style]);
    }

    /**
     * The final image prompt for one scene: style + the recurring character + the
     * scene's own description. The character description is repeated in every scene
     * on purpose — it is the only thing tying the mascot's look together across
     * otherwise independent image generations.
     */
    public static function imagePrompt(string $style, ?string $character, string $visual): string
    {
        $parts = [
            self::STYLES[$style] ?? self::STYLES[self::DEFAULT],
            'full-bleed artwork that fills the entire frame edge to edge, no border, no white margins, no frame, subject centered',
            $character ? "Recurring character: {$character}." : null,
            "Scene: {$visual}",
            'No text, no letters, no logos, no watermark.',
        ];

        return implode('. ', array_filter($parts));
    }

    /**
     * Burned-in caption look: big bold white text with a thick black outline sitting
     * above the area platforms cover with their own UI — the usual short-form style.
     */
    public static function captionConfig(): array
    {
        return [
            'font' => 'Arial',
            'font_size' => 88,
            'bold' => true,
            'color' => '#FFFFFF',
            'stroke_color' => '#000000',
            'stroke_width' => 7,
            'position' => 'bottom',
            'margin_v' => 420,
            'uppercase' => false,
        ];
    }
}
