<?php

namespace Database\Seeders;

use App\Models\Template;
use App\Models\TemplateCategory;
use App\Models\TemplateVersion;
use App\Services\Video\DefaultTemplateConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TemplateSeeder extends Seeder
{
    public function run(): void
    {
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
                'transition' => 'fast_cut',
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
    }
}
