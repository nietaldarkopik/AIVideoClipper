<?php

namespace Database\Seeders;

use App\Models\ResearchSource;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

/**
 * Registry rows for the providers in ResearchServiceProvider.
 *
 * `configuration` here is the GLOBAL default, merged under whatever a channel sets
 * on its pivot — a channel's own config always wins (see ResearchQueryBuilder).
 *
 * Note what is NOT here: API keys. Credentials come from config/research.php
 * (env-backed) and are never written to this table, never returned by the API and
 * never shown in the UI (spec section 35).
 */
class ResearchSourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            [
                'key' => 'google_trends', 'provider' => 'google_trends', 'name' => 'Google Trends', 'type' => 'trends',
                'description' => 'Topik yang sedang naik pencariannya di suatu negara (feed RSS resmi Google Trends).',
                'configuration' => ['region' => 'ID'],
            ],
            [
                'key' => 'reddit', 'provider' => 'reddit', 'name' => 'Reddit', 'type' => 'social',
                'description' => 'Diskusi komunitas. Paling akurat bila subreddit diisi per channel.',
                'configuration' => ['listing' => 'hot', 'min_score' => 50, 'time_filter' => 'day'],
            ],
            [
                'key' => 'youtube', 'provider' => 'youtube', 'name' => 'YouTube', 'type' => 'video',
                'description' => 'Video baru & paling populer (YouTube Data API v3). Butuh YOUTUBE_API_KEY.',
                'configuration' => ['region' => 'ID', 'order' => 'viewCount'],
            ],
            [
                'key' => 'google_news', 'provider' => 'google_news', 'name' => 'Google News', 'type' => 'news',
                'description' => 'Pemberitaan media untuk kata kunci channel, sadar bahasa dan region.',
                'configuration' => ['region' => 'ID'],
            ],
            [
                'key' => 'web_search', 'provider' => 'web_search', 'name' => 'Web Search', 'type' => 'general',
                'description' => 'Pencarian web umum lewat provider AI yang sudah dipakai fitur Riset Konten.',
                'configuration' => ['results_per_term' => 6],
            ],
            [
                'key' => 'rss', 'provider' => 'rss', 'name' => 'RSS / Atom', 'type' => 'news',
                'description' => 'Feed bebas. Tambahkan sumber apa pun tanpa perlu provider baru.',
                'configuration' => ['match_keywords' => false],
            ],
            [
                'key' => 'github', 'provider' => 'github', 'name' => 'GitHub', 'type' => 'code',
                'description' => 'Repo baru yang sedang ramai dibintangi. Token opsional (menaikkan rate limit).',
                'configuration' => ['min_stars' => 50, 'created_within_days' => 90],
            ],
            [
                'key' => 'hacker_news', 'provider' => 'hacker_news', 'name' => 'Hacker News', 'type' => 'news',
                'description' => 'Diskusi teknologi (Algolia API resmi, tanpa API key).',
                'configuration' => ['min_points' => 20],
            ],
            [
                'key' => 'product_hunt', 'provider' => 'product_hunt', 'name' => 'Product Hunt', 'type' => 'general',
                'description' => 'Produk yang baru diluncurkan (feed publik).',
                'configuration' => ['match_keywords' => true],
            ],
            [
                'key' => 'devto', 'provider' => 'devto', 'name' => 'Dev.to', 'type' => 'code',
                'description' => 'Artikel developer populer per tag.',
                'configuration' => ['top_days' => 7],
            ],
            [
                'key' => 'stackoverflow', 'provider' => 'stackoverflow', 'name' => 'Stack Overflow', 'type' => 'code',
                'description' => 'Pertanyaan yang sedang banyak divote — sinyal masalah nyata developer.',
                'configuration' => ['min_score' => 3],
            ],
            [
                'key' => 'tmdb', 'provider' => 'tmdb', 'name' => 'TMDB (Film & TV)', 'type' => 'media',
                'description' => 'Film & serial yang sedang tren. Butuh TMDB_API_KEY. IMDb tidak punya API publik, jadi tautan IMDb diambil lewat TMDB.',
                'configuration' => ['mode' => 'trending', 'media_type' => 'all', 'min_vote_count' => 50],
            ],
            [
                'key' => 'steam', 'provider' => 'steam', 'name' => 'Steam', 'type' => 'gaming',
                'description' => 'Rilis baru, promo, dan berita resmi game (storefront publik).',
                'configuration' => ['region' => 'ID'],
            ],
            [
                'key' => 'twitch', 'provider' => 'twitch', 'name' => 'Twitch', 'type' => 'gaming',
                'description' => 'Game yang paling banyak ditonton saat ini. Butuh TWITCH_CLIENT_ID & SECRET.',
                'configuration' => [],
            ],
        ];

        foreach ($sources as $source) {
            $existing = ResearchSource::where('key', $source['key'])->first();

            if ($existing === null) {
                ResearchSource::create($source + ['enabled' => true]);

                continue;
            }

            // `enabled` is deliberately excluded on update: re-running the seeder must
            // refresh names and descriptions without silently switching a source the
            // user turned off back on. `configuration` is excluded for the same reason —
            // it may hold the user's edited global defaults.
            $existing->update(Arr::except($source, ['key', 'enabled', 'configuration']));
        }
    }
}
