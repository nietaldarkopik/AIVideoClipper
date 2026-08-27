<?php

namespace App\Services\Video;

/**
 * Baseline caption/branding config used when a clip has no template attached,
 * and as the base layer new templates start from in the template editor.
 */
class DefaultTemplateConfig
{
    public static function config(): array
    {
        return [
            // Absent/1 = legacy shape (caption + branding only, the only two
            // sub-objects the renderer has ever read). 2 = layers-aware shape, see
            // v2Defaults() below and LayerCompositionService — a template stuck on
            // version 1 (or any config missing this key) always renders with an
            // empty layer list, so existing templates/clips are unaffected.
            'version' => 1,
            'caption' => [
                'font' => 'Arial',
                'font_size' => null, // null -> FFmpegService/SubtitleService derives from resolution
                'color' => '#FFFFFF',
                'highlight_color' => '#FFD100',
                'stroke_color' => '#000000',
                'stroke_width' => 3,
                'background' => '#000000',
                'background_opacity' => 0,
                'background_padding' => 8,
                'position' => 'bottom',
                'uppercase' => false,
                'bold' => true,
                'italic' => false,
                'animation' => 'none', // 'none' | 'fade' | 'pop'
                'highlight_active_word' => true,
                'words_per_line' => 3,
            ],
            'branding' => [
                'logo_path' => null,
                'watermark_path' => null,
                'watermark_opacity' => 0.8,
            ],
            'progress_bar' => null,
            'cta' => null,
            // null = full-bleed video (every template before this key existed, and
            // every template that doesn't opt in) — set to {x,y,width,height}
            // fractions to inset the video into a sub-region of the canvas instead,
            // typically paired with 'rect' + 'text' layers built around it (a
            // headline bar above, a branding/source bar below). See
            // FFmpegService::renderClip()'s $videoRegion param.
            'video_region' => null,
            'canvas_background_color' => '#000000',
        ];
    }

    /**
     * Starting point for a new "layers-aware" template (config version 2). Same
     * caption/branding baseline as config(), plus an empty layers list a template
     * author builds up in the editor. See LayerCompositionService for the layer
     * shape and how config['layers'] is turned into an FFmpeg filtergraph.
     */
    public static function v2Defaults(): array
    {
        return array_merge(self::config(), [
            'version' => 2,
            'layers' => [],
        ]);
    }
}
