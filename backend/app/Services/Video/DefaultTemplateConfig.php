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
        ];
    }
}
