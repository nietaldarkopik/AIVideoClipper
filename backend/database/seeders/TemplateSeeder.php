<?php

namespace Database\Seeders;

use App\Models\Template;
use App\Models\TemplateCategory;
use App\Models\TemplateVersion;
use App\Services\AI\Contracts\ImageGenerationProvider;
use App\Services\Video\DefaultTemplateConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class TemplateSeeder extends Seeder
{
    private ImageGenerationProvider $imageGen;

    public function run(ImageGenerationProvider $imageGen): void
    {
        $this->imageGen = $imageGen;
        $this->makeTemplate(
            name: 'Podcast',
            categorySlug: 'podcast',
            aspectRatio: '9:16',
            description: 'Speaker centered, dynamic subtitles with active word highlight, small logo, minimal animation.',
            config: [
                'caption' => [
                    'font' => 'Arial', 'font_size' => 64, 'color' => '#FFFFFF',
                    'highlight_color' => '#22D3EE', 'position' => 'bottom',
                    'highlight_active_word' => true, 'uppercase' => false, 'words_per_line' => 4,
                ],
                'crop_rules' => ['mode' => 'speaker_centered'],
                'progress_bar' => ['enabled' => true, 'color' => '#22D3EE', 'position' => 'top'],
                'branding' => ['logo_path' => null, 'watermark_opacity' => 0.6],
                'animation' => 'minimal',
            ],
        );

        $this->makeTemplate(
            name: 'Viral',
            categorySlug: 'motivation',
            aspectRatio: '9:16',
            description: 'Fast cuts, large captions with word highlighting, zoom-in effects, high-energy transitions.',
            config: [
                'caption' => [
                    'font' => 'Arial Black', 'font_size' => 84, 'color' => '#FFFFFF',
                    'highlight_color' => '#FFD100', 'stroke_width' => 6, 'position' => 'center',
                    'highlight_active_word' => true, 'uppercase' => true, 'words_per_line' => 2, 'bold' => true,
                ],
                'crop_rules' => ['mode' => 'smart'],
                'transition' => ['type' => 'cut', 'duration' => 0.1],
                'effects' => ['zoom_in', 'shake_on_hook'],
                'sound_effects' => ['whoosh', 'pop'],
            ],
        );

        $this->makeTemplate(
            name: 'Educational',
            categorySlug: 'education',
            aspectRatio: '9:16',
            description: 'Clean layout, large subtitles, keyword highlighting, supporting text, progress indicator.',
            config: [
                'caption' => [
                    'font' => 'Arial', 'font_size' => 60, 'color' => '#111111',
                    'background' => '#FFFFFF', 'background_opacity' => 0.9, 'stroke_width' => 0,
                    'position' => 'bottom', 'highlight_active_word' => true, 'highlight_color' => '#2563EB',
                    'uppercase' => false, 'words_per_line' => 5,
                ],
                'crop_rules' => ['mode' => 'smart'],
                'progress_bar' => ['enabled' => true, 'color' => '#2563EB', 'position' => 'top'],
            ],
        );

        $this->makeTemplate(
            name: 'News',
            categorySlug: 'news',
            aspectRatio: '16:9',
            description: 'Speaker with headline, lower-third caption bar, source branding.',
            config: [
                'caption' => [
                    'font' => 'Georgia', 'font_size' => 48, 'color' => '#FFFFFF',
                    'background' => '#B91C1C', 'background_opacity' => 0.95, 'stroke_width' => 0,
                    'position' => 'bottom', 'highlight_active_word' => false, 'uppercase' => false, 'words_per_line' => 6,
                ],
                'crop_rules' => ['mode' => 'manual'],
                'overlay' => ['lower_third' => true, 'headline' => true],
                'branding' => ['watermark_opacity' => 1.0],
            ],
        );

        // --- Expanded library: CapCut-style variety across caption font/color,
        // layout (full-bleed vs headline/name bar via video_region + layers),
        // effect (zoom/ken-burns/shake), and transition, spread across every
        // category. Fonts are kept to ones bundled with Windows (this app's ffmpeg
        // host) since SubtitleService burns captions via libass's Fontname, which
        // resolves through OS fontconfig rather than an embedded file — an
        // unlisted font name would silently fall back instead of rendering as
        // requested.

        $this->makeTemplate(
            name: 'Gaming Hype',
            categorySlug: 'gaming',
            aspectRatio: '9:16',
            description: 'Neon captions, punchy word-by-word highlight, camera shake for hype moments.',
            config: [
                'caption' => [
                    'font' => 'Impact', 'font_size' => 90, 'color' => '#39FF14',
                    'highlight_color' => '#FF00E6', 'stroke_width' => 7, 'position' => 'center',
                    'uppercase' => true, 'words_per_line' => 2, 'highlight_active_word' => true, 'animation' => 'pop',
                ],
                'effects' => ['type' => 'shake', 'intensity' => 0.12],
                'transition' => ['type' => 'cut', 'duration' => 0.2],
                'branding' => ['watermark_opacity' => 0.7],
            ],
        );

        $this->makeTemplate(
            name: 'Study Clean',
            categorySlug: 'education',
            aspectRatio: '9:16',
            description: 'Purple lesson-title bar up top, clean white caption box below, no distractions.',
            config: [
                'caption' => [
                    'font' => 'Verdana', 'font_size' => 56, 'color' => '#111827',
                    'background' => '#FFFFFF', 'background_opacity' => 0.92, 'stroke_width' => 0,
                    'position' => 'bottom', 'highlight_active_word' => true, 'highlight_color' => '#7C3AED', 'words_per_line' => 5,
                ],
                'video_region' => ['x' => 0, 'y' => 0.08, 'width' => 1, 'height' => 0.84],
                'canvas_background_color' => '#0F172A',
                'layers' => [
                    ['id' => 'top-bar', 'type' => 'rect', 'z_index' => 1, 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 0.08, 'props' => ['color' => '#7C3AED']],
                    ['id' => 'top-label', 'type' => 'text', 'z_index' => 2, 'x' => 0.5, 'y' => 0.04, 'width' => 0.9, 'height' => 0.07, 'props' => ['content' => 'LESSON', 'font' => 'Verdana', 'color' => '#FFFFFF', 'align' => 'center']],
                ],
            ],
        );

        $this->makeTemplate(
            name: 'Business Corporate',
            categorySlug: 'business',
            aspectRatio: '16:9',
            description: 'Navy lower-third caption bar, slow confident zoom, crossfade between segments.',
            config: [
                'caption' => [
                    'font' => 'Georgia', 'font_size' => 44, 'color' => '#F5F5F5',
                    'background' => '#0B1D3A', 'background_opacity' => 0.9, 'stroke_width' => 0,
                    'position' => 'bottom', 'words_per_line' => 6,
                ],
                'branding' => ['watermark_opacity' => 1.0],
                'effects' => ['type' => 'ken_burns', 'intensity' => 0.08],
                'transition' => ['type' => 'fade', 'duration' => 0.5],
            ],
        );

        $this->makeTemplate(
            name: 'Interview Split-Screen',
            categorySlug: 'interview',
            aspectRatio: '9:16',
            description: 'Captions up top, dark name/title bar pinned to the bottom.',
            config: [
                'caption' => [
                    'font' => 'Trebuchet MS', 'font_size' => 60, 'color' => '#FFFFFF',
                    'highlight_color' => '#22D3EE', 'position' => 'top', 'words_per_line' => 4, 'highlight_active_word' => true,
                ],
                'video_region' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 0.82],
                'canvas_background_color' => '#111111',
                'layers' => [
                    ['id' => 'name-bar', 'type' => 'rect', 'z_index' => 1, 'x' => 0, 'y' => 0.82, 'width' => 1, 'height' => 0.18, 'props' => ['color' => '#111111']],
                    ['id' => 'name-text', 'type' => 'text', 'z_index' => 2, 'x' => 0.5, 'y' => 0.91, 'width' => 0.9, 'height' => 0.14, 'props' => ['content' => 'Guest Name', 'font' => 'Trebuchet MS', 'color' => '#22D3EE', 'align' => 'center']],
                ],
                'transition' => ['type' => 'fade', 'duration' => 0.4],
            ],
        );

        $this->makeTemplate(
            name: 'Comedy Meme',
            categorySlug: 'comedy',
            aspectRatio: '1:1',
            description: 'Big yellow meme-style caption, thick black outline, punch-in zoom on the punchline.',
            config: [
                'caption' => [
                    'font' => 'Impact', 'font_size' => 76, 'color' => '#FFEB0A',
                    'stroke_color' => '#000000', 'stroke_width' => 8, 'position' => 'center',
                    'uppercase' => true, 'words_per_line' => 2,
                ],
                'effects' => ['type' => 'zoom_in', 'intensity' => 0.1],
            ],
        );

        $this->makeTemplate(
            name: 'Vlog Casual',
            categorySlug: 'vlog',
            aspectRatio: '9:16',
            description: 'Soft rounded captions, gentle Ken Burns drift, friendly and lo-fi.',
            config: [
                'caption' => [
                    'font' => 'Verdana', 'font_size' => 58, 'color' => '#FFFFFF',
                    'highlight_color' => '#FFB6C1', 'position' => 'bottom', 'bold' => true, 'words_per_line' => 4, 'animation' => 'fade',
                ],
                'effects' => ['type' => 'ken_burns', 'intensity' => 0.1],
                'branding' => ['watermark_opacity' => 0.6],
            ],
        );

        $this->makeTemplate(
            name: 'Cinematic Storytelling',
            categorySlug: 'storytelling',
            aspectRatio: '16:9',
            description: 'Letterboxed frame, minimal serif captions, slow zoom-and-drift, crossfade cuts.',
            config: [
                'caption' => [
                    'font' => 'Georgia', 'font_size' => 42, 'color' => '#F5F5F5',
                    'stroke_width' => 2, 'position' => 'bottom', 'words_per_line' => 7,
                ],
                'video_region' => ['x' => 0, 'y' => 0.1, 'width' => 1, 'height' => 0.8],
                'canvas_background_color' => '#000000',
                'effects' => ['type' => 'ken_burns', 'intensity' => 0.18],
                'transition' => ['type' => 'fade', 'duration' => 0.6],
            ],
        );

        $this->makeTemplate(
            name: 'Product Showcase',
            categorySlug: 'product',
            aspectRatio: '1:1',
            description: 'Clean white caption card, orange progress bar, subtle push-in on the product.',
            config: [
                'caption' => [
                    'font' => 'Arial', 'font_size' => 50, 'color' => '#111111',
                    'background' => '#FFFFFF', 'background_opacity' => 0.85, 'stroke_width' => 0, 'position' => 'bottom', 'words_per_line' => 5,
                ],
                'layers' => [
                    ['id' => 'progress', 'type' => 'progress_bar', 'z_index' => 1, 'props' => ['color' => '#EA580C', 'background_color' => '#000000', 'height_px' => 8, 'position' => 'bottom']],
                ],
                'effects' => ['type' => 'zoom_in', 'intensity' => 0.08],
                'branding' => ['watermark_opacity' => 1.0],
            ],
        );

        $this->makeTemplate(
            name: 'Minimal Personal Brand',
            categorySlug: 'personal-branding',
            aspectRatio: '9:16',
            description: 'No box, no stroke, just clean white captions — stays out of the way.',
            config: [
                'caption' => [
                    'font' => 'Trebuchet MS', 'font_size' => 52, 'color' => '#FFFFFF',
                    'stroke_width' => 0, 'background' => '#000000', 'background_opacity' => 0,
                    'position' => 'bottom', 'words_per_line' => 5, 'highlight_active_word' => false, 'bold' => false,
                ],
                'branding' => ['watermark_opacity' => 0.5],
            ],
        );

        $this->makeTemplate(
            name: 'Sports Highlight',
            categorySlug: 'sports',
            aspectRatio: '9:16',
            description: 'Red "HIGHLIGHT" bar up top, huge bold captions, camera shake on the big plays.',
            config: [
                'caption' => [
                    'font' => 'Impact', 'font_size' => 82, 'color' => '#FFFFFF',
                    'highlight_color' => '#EF4444', 'stroke_width' => 6, 'position' => 'center',
                    'uppercase' => true, 'words_per_line' => 2, 'highlight_active_word' => true,
                ],
                'video_region' => ['x' => 0, 'y' => 0.06, 'width' => 1, 'height' => 0.94],
                'canvas_background_color' => '#0A0A0A',
                'layers' => [
                    ['id' => 'headline-bar', 'type' => 'rect', 'z_index' => 1, 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 0.06, 'props' => ['color' => '#EF4444']],
                    ['id' => 'headline-text', 'type' => 'text', 'z_index' => 2, 'x' => 0.5, 'y' => 0.03, 'width' => 0.9, 'height' => 0.05, 'props' => ['content' => 'HIGHLIGHT', 'font' => 'Impact', 'color' => '#FFFFFF', 'align' => 'center']],
                ],
                'effects' => ['type' => 'shake', 'intensity' => 0.1],
            ],
        );

        $this->makeTemplate(
            name: 'Finance Chart Talk',
            categorySlug: 'finance',
            aspectRatio: '16:9',
            description: 'Dark green lower-third, highlighted keywords for numbers and tickers.',
            config: [
                'caption' => [
                    'font' => 'Arial', 'font_size' => 44, 'color' => '#FFFFFF',
                    'background' => '#064E3B', 'background_opacity' => 0.92, 'stroke_width' => 0,
                    'position' => 'bottom', 'highlight_color' => '#22C55E', 'highlight_active_word' => true, 'words_per_line' => 6,
                ],
                'branding' => ['watermark_opacity' => 1.0],
                'effects' => ['type' => 'zoom_in', 'intensity' => 0.06],
            ],
        );

        $this->makeTemplate(
            name: 'Beauty Glow',
            categorySlug: 'beauty',
            aspectRatio: '9:16',
            description: 'Soft pink highlight, gentle fade-in captions, warm Ken Burns glow.',
            config: [
                'caption' => [
                    'font' => 'Trebuchet MS', 'font_size' => 56, 'color' => '#FFFFFF',
                    'highlight_color' => '#FF6EC7', 'stroke_width' => 2, 'position' => 'bottom', 'words_per_line' => 4, 'animation' => 'fade',
                ],
                'effects' => ['type' => 'ken_burns', 'intensity' => 0.14],
                'branding' => ['watermark_opacity' => 0.6],
            ],
        );

        $this->makeTemplate(
            name: 'Travel Cinematic',
            categorySlug: 'travel',
            aspectRatio: '16:9',
            description: 'Letterboxed, italic serif captions, sweeping Ken Burns drift, long crossfades.',
            config: [
                'caption' => [
                    'font' => 'Georgia', 'font_size' => 40, 'color' => '#FFFFFF',
                    'stroke_width' => 2, 'position' => 'bottom', 'words_per_line' => 6, 'italic' => true,
                ],
                'video_region' => ['x' => 0, 'y' => 0.12, 'width' => 1, 'height' => 0.76],
                'canvas_background_color' => '#000000',
                'effects' => ['type' => 'ken_burns', 'intensity' => 0.2],
                'transition' => ['type' => 'fade', 'duration' => 0.7],
            ],
        );

        $this->makeTemplate(
            name: 'Recipe Quick',
            categorySlug: 'food',
            aspectRatio: '1:1',
            description: 'Bold step-by-step captions, orange progress bar, quick punch-in per step.',
            config: [
                'caption' => [
                    'font' => 'Arial Black', 'font_size' => 62, 'color' => '#FFFFFF',
                    'highlight_color' => '#F97316', 'stroke_color' => '#000000', 'stroke_width' => 5,
                    'position' => 'center', 'uppercase' => true, 'words_per_line' => 3, 'highlight_active_word' => true,
                ],
                'layers' => [
                    ['id' => 'progress', 'type' => 'progress_bar', 'z_index' => 1, 'props' => ['color' => '#F97316', 'background_color' => '#000000', 'height_px' => 10, 'position' => 'top']],
                ],
                'effects' => ['type' => 'zoom_in', 'intensity' => 0.1],
            ],
        );

        $this->makeTemplate(
            name: 'Fitness Energy',
            categorySlug: 'fitness',
            aspectRatio: '9:16',
            description: 'High-energy orange highlight, big bold captions, constant camera shake.',
            config: [
                'caption' => [
                    'font' => 'Impact', 'font_size' => 84, 'color' => '#FFFFFF',
                    'highlight_color' => '#F97316', 'stroke_width' => 6, 'position' => 'center',
                    'uppercase' => true, 'words_per_line' => 2, 'highlight_active_word' => true,
                ],
                'effects' => ['type' => 'shake', 'intensity' => 0.14],
                'transition' => ['type' => 'cut', 'duration' => 0.2],
            ],
        );

        $this->makeTemplate(
            name: 'Tech Unboxing',
            categorySlug: 'tech-review',
            aspectRatio: '9:16',
            description: 'Cool blue highlight, thin progress bar, slow deliberate zoom.',
            config: [
                'caption' => [
                    'font' => 'Verdana', 'font_size' => 58, 'color' => '#FFFFFF',
                    'highlight_color' => '#3B82F6', 'position' => 'bottom', 'words_per_line' => 4, 'highlight_active_word' => true,
                ],
                'layers' => [
                    ['id' => 'progress', 'type' => 'progress_bar', 'z_index' => 1, 'props' => ['color' => '#3B82F6', 'background_color' => '#000000', 'height_px' => 6, 'position' => 'bottom']],
                ],
                'effects' => ['type' => 'zoom_in', 'intensity' => 0.07],
            ],
        );

        $this->makeTemplate(
            name: 'Karaoke Lyric',
            categorySlug: 'music',
            aspectRatio: '9:16',
            description: 'One word at a time, huge center captions, cyan pop-highlight — sing-along style.',
            config: [
                'caption' => [
                    'font' => 'Arial Black', 'font_size' => 88, 'color' => '#FFFFFF',
                    'highlight_color' => '#22D3EE', 'stroke_color' => '#7C3AED', 'stroke_width' => 4,
                    'position' => 'center', 'words_per_line' => 1, 'highlight_active_word' => true, 'animation' => 'pop',
                ],
            ],
        );

        $this->makeTemplate(
            name: 'True Crime Mystery',
            categorySlug: 'true-crime',
            aspectRatio: '9:16',
            description: 'Typewriter-style monospace captions on a black box, slow ominous zoom.',
            config: [
                'caption' => [
                    'font' => 'Courier New', 'font_size' => 54, 'color' => '#E5E5E5',
                    'background' => '#000000', 'background_opacity' => 0.7, 'stroke_width' => 0,
                    'position' => 'bottom', 'words_per_line' => 5, 'highlight_color' => '#B91C1C', 'highlight_active_word' => true,
                ],
                'canvas_background_color' => '#000000',
                'effects' => ['type' => 'ken_burns', 'intensity' => 0.1],
                'transition' => ['type' => 'fade', 'duration' => 0.5],
            ],
        );

        $this->makeTemplate(
            name: 'ASMR Chill',
            categorySlug: 'vlog',
            aspectRatio: '9:16',
            description: 'Whisper-quiet lavender captions, no box, no motion — stays calm.',
            config: [
                'caption' => [
                    'font' => 'Verdana', 'font_size' => 48, 'color' => '#E9D5FF',
                    'stroke_width' => 0, 'background' => '#000000', 'background_opacity' => 0,
                    'position' => 'bottom', 'words_per_line' => 5, 'bold' => false, 'animation' => 'fade',
                ],
                'branding' => ['watermark_opacity' => 0.4],
            ],
        );

        $this->makeTemplate(
            name: 'Documentary Serious',
            categorySlug: 'news',
            aspectRatio: '16:9',
            description: 'Grey-slate caption bar, measured serif type, slow drift, crossfade cuts.',
            config: [
                'caption' => [
                    'font' => 'Georgia', 'font_size' => 42, 'color' => '#FFFFFF',
                    'background' => '#1F2937', 'background_opacity' => 0.9, 'stroke_width' => 0, 'position' => 'bottom', 'words_per_line' => 7,
                ],
                'branding' => ['watermark_opacity' => 1.0],
                'effects' => ['type' => 'ken_burns', 'intensity' => 0.12],
                'transition' => ['type' => 'fade', 'duration' => 0.6],
            ],
        );
    }

    private function makeTemplate(string $name, string $categorySlug, string $aspectRatio, string $description, array $config): void
    {
        $category = TemplateCategory::where('slug', $categorySlug)->first();
        [$w, $h] = \App\Services\Video\AspectRatio::resolution($aspectRatio);

        $template = Template::updateOrCreate(
            ['slug' => Str::slug($name) . '-system'],
            [
                'template_category_id' => $category?->id,
                'name' => $name,
                'description' => $description,
                'aspect_ratio' => $aspectRatio,
                'resolution_width' => $w,
                'resolution_height' => $h,
                'status' => 'published',
                'is_system' => true,
            ]
        );

        if ($template->currentVersion) {
            return; // already seeded with a version
        }

        $version = TemplateVersion::create([
            'template_id' => $template->id,
            'version_number' => 1,
            'label' => 'v1',
            'is_published' => true,
            'config' => array_replace_recursive(DefaultTemplateConfig::config(), $config),
        ]);

        $template->update(['current_version_id' => $version->id]);

        // Best-effort: a failed cover generation (external provider hiccup, no
        // provider configured beyond the mock) must never abort the whole seed run
        // — the template is already fully usable without a thumbnail.
        try {
            $disk = Storage::disk('media');
            $relative = "templates/{$template->id}/thumbnail.jpg";
            $prompt = sprintf(
                'Eye-catching %s video template cover thumbnail, dramatic and high-contrast, no text or logos. Style: %s.',
                $h > $w ? 'vertical' : ($h === $w ? 'square' : 'horizontal'),
                $description,
            );
            $this->imageGen->generateCoverImage($prompt, $disk->path($relative));
            $template->update(['thumbnail_path' => $relative]);
        } catch (Throwable $e) {
            Log::warning('Template thumbnail generation failed during seeding', [
                'template' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
