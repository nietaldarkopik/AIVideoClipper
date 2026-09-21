<?php

namespace Database\Seeders;

use App\Models\ChannelTemplate;
use Illuminate\Database\Seeder;

/**
 * Reusable starting points for "Add Channel" (spec section 5). Selecting one
 * pre-populates the form; everything stays editable before saving, and the saved
 * channel keeps no link back to the template.
 */
class ChannelTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'key' => 'youtube_news', 'name' => 'YouTube News', 'platform_key' => 'youtube', 'sort_order' => 10,
                'description' => 'Berita dan topik viral, riset beberapa kali sehari.',
                'defaults' => [
                    'niche' => 'Berita & Topik Viral',
                    'sub_niches' => ['berita nasional', 'berita internasional', 'isu sosial', 'peristiwa viral'],
                    'content_style' => ['fast commentary', 'news analysis', 'opini'],
                    'content_types' => ['commentary', 'berita'],
                    'content_formats' => ['long_form', 'shorts'],
                    'tone' => 'cepat dan tajam',
                    'research_frequency' => 'every_n_hours',
                    'interval_hours' => 6,
                    'ideas_per_run' => 5,
                    'research_source_keys' => ['google_trends', 'google_news', 'youtube', 'reddit', 'rss'],
                ],
            ],
            [
                'key' => 'youtube_technology', 'name' => 'YouTube Technology', 'platform_key' => 'youtube', 'sort_order' => 20,
                'description' => 'Teknologi, pemrograman, AI, server dan automasi.',
                'defaults' => [
                    'niche' => 'Teknologi & Pemrograman',
                    'sub_niches' => ['AI', 'programming', 'server', 'linux', 'networking', 'automation'],
                    'content_style' => ['tutorial', 'eksperimen', 'tech commentary'],
                    'content_types' => ['tutorial', 'commentary'],
                    'content_formats' => ['long_form', 'shorts'],
                    'tone' => 'informatif dan personal',
                    'research_frequency' => 'daily',
                    'research_times' => ['06:00'],
                    'ideas_per_run' => 5,
                    'research_source_keys' => ['google_trends', 'youtube', 'reddit', 'github', 'hacker_news', 'devto', 'stackoverflow', 'product_hunt'],
                ],
            ],
            [
                'key' => 'youtube_gaming', 'name' => 'YouTube Gaming', 'platform_key' => 'youtube', 'sort_order' => 30,
                'description' => 'Game baru, gameplay, tips dan berita gaming.',
                'defaults' => [
                    'niche' => 'Gaming',
                    'sub_niches' => ['game baru', 'gameplay', 'tips & trik', 'walkthrough', 'berita gaming'],
                    'content_style' => ['gameplay', 'tips', 'challenge', 'funny moments'],
                    'content_types' => ['gameplay', 'tips'],
                    'content_formats' => ['long_form', 'shorts', 'live'],
                    'tone' => 'seru dan santai',
                    'research_frequency' => 'daily',
                    'research_times' => ['07:00'],
                    'ideas_per_run' => 5,
                    'research_source_keys' => ['google_trends', 'youtube', 'reddit', 'steam', 'twitch', 'rss'],
                ],
            ],
            [
                'key' => 'youtube_movie', 'name' => 'YouTube Movie', 'platform_key' => 'youtube', 'sort_order' => 40,
                'description' => 'Alur cerita film, ending explained dan teori film.',
                'defaults' => [
                    'niche' => 'Film & Serial',
                    'sub_niches' => ['alur cerita film', 'ending explained', 'teori film', 'analisis karakter'],
                    'content_style' => ['storytelling', 'movie recap', 'penjelasan'],
                    'content_types' => ['storytelling', 'recap'],
                    'content_formats' => ['long_form', 'shorts'],
                    'tone' => 'naratif',
                    'research_frequency' => 'daily',
                    'research_times' => ['08:00'],
                    'ideas_per_run' => 4,
                    'research_source_keys' => ['google_trends', 'youtube', 'reddit', 'tmdb', 'google_news'],
                ],
            ],
            [
                'key' => 'islamic_news', 'name' => 'Islamic News', 'platform_key' => 'youtube', 'sort_order' => 50,
                'description' => 'Isu keislaman, dunia muslim dan sejarah Islam.',
                'defaults' => [
                    'niche' => 'Islam & Dunia Muslim',
                    'sub_niches' => ['isu keislaman', 'dunia muslim', 'sejarah Islam', 'isu sosial'],
                    'content_style' => ['analisis berita', 'commentary', 'edukasi', 'storytelling'],
                    'content_types' => ['commentary', 'edukasi'],
                    'content_formats' => ['long_form', 'shorts'],
                    'tone' => 'tenang dan berimbang',
                    'research_frequency' => 'daily',
                    'research_times' => ['06:00'],
                    'ideas_per_run' => 4,
                    'research_source_keys' => ['google_trends', 'google_news', 'reddit', 'youtube', 'rss'],
                ],
            ],
            [
                'key' => 'reaction', 'name' => 'Reaction', 'platform_key' => 'youtube', 'sort_order' => 60,
                'description' => 'Reaksi terhadap video viral, trailer dan konten internet.',
                'defaults' => [
                    'niche' => 'Video Viral & Budaya Internet',
                    'sub_niches' => ['video viral', 'trailer film', 'video lucu', 'video teknologi'],
                    'content_style' => ['reaction', 'commentary', 'hiburan'],
                    'content_types' => ['reaction'],
                    'content_formats' => ['long_form', 'shorts'],
                    'tone' => 'ekspresif',
                    'research_frequency' => 'twice_daily',
                    'research_times' => ['09:00', '19:00'],
                    'ideas_per_run' => 5,
                    'research_source_keys' => ['youtube', 'google_trends', 'reddit', 'rss'],
                ],
            ],
            [
                'key' => 'educational', 'name' => 'Educational', 'platform_key' => 'youtube', 'sort_order' => 70,
                'description' => 'Konten akademik dan pengetahuan praktis.',
                'defaults' => [
                    'niche' => 'Edukasi',
                    'sub_niches' => ['tutorial', 'pengetahuan praktis', 'akademik'],
                    'content_style' => ['edukatif', 'tutorial', 'akademik'],
                    'content_types' => ['tutorial', 'edukasi'],
                    'content_formats' => ['long_form'],
                    'tone' => 'jelas dan terstruktur',
                    'research_frequency' => 'daily',
                    'research_times' => ['06:00'],
                    'ideas_per_run' => 3,
                    'research_source_keys' => ['google_trends', 'youtube', 'google_news', 'reddit', 'rss'],
                ],
            ],
            [
                'key' => 'custom', 'name' => 'Custom', 'platform_key' => null, 'sort_order' => 999,
                'description' => 'Mulai dari kosong dan atur semuanya sendiri.',
                'defaults' => [
                    'research_frequency' => 'daily',
                    'research_times' => ['06:00'],
                    'ideas_per_run' => 5,
                    'research_source_keys' => ['google_trends', 'google_news'],
                ],
            ],
        ];

        foreach ($templates as $template) {
            ChannelTemplate::updateOrCreate(['key' => $template['key']], $template);
        }
    }
}
