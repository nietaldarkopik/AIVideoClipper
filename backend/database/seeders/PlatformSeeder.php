<?php

namespace Database\Seeders;

use App\Models\Platform;
use Illuminate\Database\Seeder;

/**
 * Starting set of platforms. Not a fixed list: platforms are rows, and the UI can
 * add more without a migration or a code change (spec rule 7).
 *
 * default_strategy is the platform-aware content strategy from spec section 28 —
 * copied into a channel when it is created, then owned by that channel.
 */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $platforms = [
            [
                'key' => 'youtube', 'name' => 'YouTube', 'type' => 'video', 'sort_order' => 10,
                'default_strategy' => [
                    'content_formats' => ['long_form', 'shorts'],
                    'content_types' => ['tutorial', 'commentary', 'storytelling', 'educational'],
                    'hook_styles' => ['pertanyaan', 'klaim kuat', 'cold open'],
                    'tone' => 'informatif',
                ],
            ],
            [
                'key' => 'tiktok', 'name' => 'TikTok', 'type' => 'short_video', 'sort_order' => 20,
                'default_strategy' => [
                    'content_formats' => ['short_form'],
                    'content_types' => ['trending', 'commentary', 'tips'],
                    'hook_styles' => ['hook 3 detik', 'pertanyaan provokatif'],
                    'tone' => 'cepat dan santai',
                ],
            ],
            [
                'key' => 'instagram', 'name' => 'Instagram', 'type' => 'short_video', 'sort_order' => 30,
                'default_strategy' => [
                    'content_formats' => ['reels', 'carousel'],
                    'content_types' => ['tips', 'storytelling', 'edukasi singkat'],
                    'hook_styles' => ['visual hook', 'teks besar di frame pertama'],
                    'tone' => 'ringkas',
                ],
            ],
            [
                'key' => 'facebook', 'name' => 'Facebook', 'type' => 'video', 'sort_order' => 40,
                'default_strategy' => [
                    'content_formats' => ['video', 'reels'],
                    'content_types' => ['commentary', 'berita', 'storytelling'],
                    'hook_styles' => ['pernyataan pembuka', 'pertanyaan'],
                    'tone' => 'percakapan',
                ],
            ],
            [
                'key' => 'x', 'name' => 'X', 'type' => 'text', 'sort_order' => 50,
                'default_strategy' => [
                    'content_formats' => ['thread', 'post'],
                    'content_types' => ['opini', 'berita', 'analisis singkat'],
                    'hook_styles' => ['baris pertama tajam'],
                    'tone' => 'padat',
                ],
            ],
            [
                'key' => 'threads', 'name' => 'Threads', 'type' => 'text', 'sort_order' => 60,
                'default_strategy' => [
                    'content_formats' => ['post', 'thread'],
                    'content_types' => ['opini', 'diskusi'],
                    'tone' => 'santai',
                ],
            ],
            [
                'key' => 'website', 'name' => 'Website', 'type' => 'web', 'sort_order' => 70,
                'default_strategy' => [
                    'content_formats' => ['artikel', 'long_form'],
                    'content_types' => ['tutorial', 'analisis', 'panduan'],
                    'tone' => 'formal',
                ],
            ],
            [
                'key' => 'podcast', 'name' => 'Podcast', 'type' => 'audio', 'sort_order' => 80,
                'default_strategy' => [
                    'content_formats' => ['episode', 'clip'],
                    'content_types' => ['diskusi', 'wawancara', 'monolog'],
                    'tone' => 'percakapan',
                ],
            ],
        ];

        foreach ($platforms as $platform) {
            // updateOrCreate, keyed on `key`: re-running the seeder must never duplicate
            // a platform that channels already point at via platform_id.
            Platform::updateOrCreate(['key' => $platform['key']], $platform);
        }
    }
}
