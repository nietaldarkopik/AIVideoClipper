<?php

namespace App\Services\Video;

/**
 * Baseline cover/thumbnail style. A cover is composed of four independent,
 * individually-styleable pieces stacked over the background image, which is
 * what lets two templates look genuinely different instead of "the same dark
 * bar with white text":
 *
 *   background — the image itself, plus a full-color wash and a directional
 *                darkening gradient (so a bright frame still reads).
 *   kicker     — a small, high-contrast eyebrow label above the headline
 *                ("FAKTA", "EPISODE 1"), drawn in its own colored box.
 *   text       — the headline, wrapped into lines that can each get their own
 *                hugging color box (the TikTok/MrBeast look) and alternating /
 *                accent line colors, with both a glyph stroke and a drop shadow.
 *   subline    — a small supporting line below the headline, own colored box.
 *   badge      — the existing corner badge.
 *
 * See FFmpegService::renderCoverImage() for how each field is actually drawn,
 * and the cover-template editor's live preview, which mirrors this same layout
 * math in CSS.
 */
class DefaultCoverTemplateConfig
{
    public static function config(): array
    {
        return [
            // 'clip_frame' (every template before this key existed) grabs a real
            // frame from the clip's own rendered video, exactly as before.
            // 'ai_generated' instead asks ImageGenerationProvider to synthesize a
            // whole background scene from 'ai_prompt' — the still-image sibling
            // of RenderClipJob's AI reaction-intro cover — falling back to the
            // clip's own frame if generation fails or MockImageGenerationProvider
            // is bound (see CoverGeneratorService).
            'background' => [
                'source' => 'clip_frame',
                'ai_prompt' => null,
                // Flat color wash over the whole frame — this is what gives a
                // template its overall "temperature" (warm orange, cold cyan,
                // deep purple) instead of every cover looking like raw footage.
                'overlay_color' => '#000000',
                'overlay_opacity' => 0.12,
                // Directional darkening ramp behind the text block, drawn as a
                // stack of stepped bands (ffmpeg has no cheap true gradient).
                'gradient' => [
                    'enabled' => true,
                    'color' => '#000000',
                    'opacity' => 0.8,
                    'position' => 'bottom', // 'bottom' | 'top'
                    'size' => 0.45,         // fraction of canvas height
                ],
            ],
            'kicker' => [
                'enabled' => false,
                'text' => 'FAKTA',
                'color' => '#111111',
                'background' => '#FFD100',
                'background_opacity' => 1.0,
                'font_scale' => 0.045, // fraction of canvas width
            ],
            'text' => [
                'font_size' => null,   // null -> derived from font_scale below
                'font_scale' => 0.085,
                'color' => '#FFFFFF',
                // Applied per LINE (not per word — ffmpeg can't measure a word's
                // width mid-line), which is what makes a two-tone headline
                // possible: see 'highlight_mode'.
                'highlight_color' => '#FFD100',
                'highlight_mode' => 'none', // none | first_line | last_line | alternate
                'stroke_color' => '#000000',
                'stroke_width' => 6,
                'shadow_color' => '#000000',
                'shadow_x' => 4,
                'shadow_y' => 5,
                // 'lines' gives each wrapped line its own box hugging the text,
                // 'band' one full-width bar behind the whole block, 'none' just
                // stroke+shadow over the image.
                'block_style' => 'lines',
                'background' => '#000000',
                'background_opacity' => 0.55,
                'position' => 'bottom', // top | center | bottom
                'align' => 'center',    // center | left
                'uppercase' => true,
                'wrap_chars' => 16,
            ],
            'subline' => [
                'enabled' => false,
                'text' => '',
                'color' => '#FFFFFF',
                'background' => '#E11D48',
                'background_opacity' => 1.0,
                'font_scale' => 0.038,
            ],
            'badge' => [
                'enabled' => false,
                'text' => 'VIRAL',
                'color' => '#FF3B30',
                'text_color' => '#FFFFFF',
            ],
        ];
    }
}
