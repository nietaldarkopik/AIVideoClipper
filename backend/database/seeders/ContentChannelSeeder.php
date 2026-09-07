<?php

namespace Database\Seeders;

use App\Models\ContentChannel;
use App\Models\Platform;
use App\Models\ResearchSource;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The eight starting channels from the spec.
 *
 * These are DATA, not behaviour: nothing in the engine, scheduler or providers
 * refers to any of these names. Deleting every row here leaves a fully working
 * system that simply has no channels yet — which is the point of spec rule 3.
 */
class ContentChannelSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::where('email', 'admin@clipper.test')->first() ?? User::orderBy('id')->first();

        if ($owner === null) {
            // Channels are user-owned; without a user there is nothing to attach them to.
            return;
        }

        $platforms = Platform::pluck('id', 'key');
        $sourceIds = ResearchSource::pluck('id', 'key');

        foreach ($this->channels() as $definition) {
            $sourceKeys = $definition['research_source_keys'];
            unset($definition['research_source_keys']);

            $platformKey = $definition['platform_key'];
            unset($definition['platform_key']);

            if (! isset($platforms[$platformKey])) {
                continue;
            }

            $channel = ContentChannel::updateOrCreate(
                // Name + owner + platform is the natural key: the same brand can exist on
                // two platforms as two channels with their own strategies.
                ['user_id' => $owner->id, 'platform_id' => $platforms[$platformKey], 'name' => $definition['name']],
                $definition + ['user_id' => $owner->id, 'platform_id' => $platforms[$platformKey]],
            );

            $attach = [];
            foreach ($sourceKeys as $priority => $key) {
                if (isset($sourceIds[$key])) {
                    $attach[$sourceIds[$key]] = [
                        'enabled' => true,
                        'weight' => 1.00,
                        // Priority follows the declared order, so a channel's most trusted
                        // sources run before the ones most likely to be slow.
                        'priority' => ($priority + 1) * 10,
                        // Array, not json_encode(): the relation uses ChannelResearchSource
                        // as its pivot, so sync() applies that model's `array` cast — encoding
                        // here as well would store a double-encoded string.
                        'configuration' => $this->sourceConfigFor($channel, $key),
                    ];
                }
            }

            // sync, not syncWithoutDetaching: re-seeding should restore the documented
            // source set for these demo channels rather than accumulate stale ones.
            $channel->researchSources()->sync($attach);
        }
    }

    /**
     * Per-channel source configuration — the concrete example of "do not force every
     * channel to use every provider, and let each configure a source differently"
     * (spec sections 8 and 9).
     *
     * @return array<string, mixed>
     */
    private function sourceConfigFor(ContentChannel $channel, string $sourceKey): array
    {
        $subredditsByChannel = [
            'Taofik Basuki' => ['programming', 'artificial', 'LocalLLaMA', 'selfhosted', 'linux', 'devops'],
            'Netizen Muslim' => ['islam', 'worldnews', 'indonesia'],
            'Kang Basooki' => ['indonesia', 'mildlyinteresting', 'todayilearned'],
            'Mr. Off-Peak' => ['worldnews', 'indonesia', 'news'],
            'Movie Script' => ['movies', 'MovieDetails', 'FanTheories'],
            'Fakultas Teknik Unwim' => ['civilengineering', 'architecture', 'engineering'],
            'Kang Basooki React' => ['videos', 'nextfuckinglevel', 'Damnthatsinteresting'],
            'Exist Gaming' => ['gaming', 'Games', 'pcgaming'],
        ];

        return match ($sourceKey) {
            'reddit' => ['subreddits' => $subredditsByChannel[$channel->name] ?? [], 'min_score' => 50, 'listing' => 'hot'],
            'youtube' => ['region' => 'ID', 'order' => 'viewCount'] + $this->youtubeCategoryFor($channel->name),
            'google_trends', 'google_news', 'steam' => ['region' => 'ID'],
            'github' => ['min_stars' => 100, 'created_within_days' => 120],
            'tmdb' => ['mode' => 'trending', 'media_type' => 'all', 'min_vote_count' => 100],
            'rss' => ['feed_urls' => $this->feedsFor($channel->name), 'match_keywords' => true],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private function youtubeCategoryFor(string $channelName): array
    {
        // YouTube's own category ids: 20 Gaming, 28 Science & Technology, 25 News.
        return match ($channelName) {
            'Exist Gaming' => ['video_category_id' => '20'],
            'Taofik Basuki' => ['video_category_id' => '28'],
            'Mr. Off-Peak' => ['video_category_id' => '25'],
            default => [],
        };
    }

    /**
     * @return string[]
     */
    private function feedsFor(string $channelName): array
    {
        return match ($channelName) {
            'Taofik Basuki' => ['https://news.ycombinator.com/rss'],
            'Fakultas Teknik Unwim' => ['https://www.sciencedaily.com/rss/matter_energy/engineering.xml'],
            'Exist Gaming' => ['https://www.pcgamer.com/rss/'],
            'Movie Script' => ['https://www.slashfilm.com/feed/'],
            default => [],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function channels(): array
    {
        return [
            [
                'platform_key' => 'youtube', 'name' => 'Taofik Basuki', 'handle' => '@taofikbasuki',
                'description' => 'Teknologi, pemrograman, AI, server dan automasi dari pengalaman sendiri.',
                'niche' => 'Teknologi & Pemrograman',
                'sub_niches' => ['Programming', 'AI', 'Server', 'Linux', 'Networking', 'Automation', 'Developer Experience'],
                'keywords' => ['AI', 'LLM', 'Docker', 'Linux', 'PHP', 'Laravel', 'GPU', 'self-hosted', 'automation'],
                'excluded_keywords' => ['crypto giveaway', 'judi online'],
                'target_audience' => 'Developer dan IT enthusiast Indonesia',
                'content_style' => ['Tutorial', 'Eksperimen', 'Tech commentary', 'Pengalaman pribadi'],
                'content_types' => ['tutorial', 'commentary'], 'content_formats' => ['long_form', 'shorts'],
                'tone' => 'informatif dan personal', 'hook_styles' => ['pertanyaan', 'klaim kuat'],
                'scheduler_enabled' => true, 'research_frequency' => 'daily', 'research_times' => ['06:00'], 'ideas_per_run' => 5,
                'research_source_keys' => ['google_trends', 'youtube', 'reddit', 'github', 'hacker_news', 'devto', 'stackoverflow', 'product_hunt', 'google_news', 'rss'],
            ],
            [
                'platform_key' => 'youtube', 'name' => 'Netizen Muslim', 'handle' => '@netizenmuslim',
                'description' => 'Isu keislaman, dunia muslim dan sejarah Islam.',
                'niche' => 'Islam & Dunia Muslim',
                'sub_niches' => ['Isu keislaman', 'Dunia muslim', 'Isu sosial', 'Sejarah Islam'],
                'keywords' => ['Islam', 'muslim', 'Palestina', 'sejarah Islam', 'dunia Islam'],
                'excluded_keywords' => ['ujaran kebencian'],
                'target_audience' => 'Muslim Indonesia yang mengikuti isu terkini',
                'content_style' => ['Analisis berita', 'Commentary', 'Edukasi', 'Storytelling'],
                'content_types' => ['commentary', 'edukasi'], 'content_formats' => ['long_form', 'shorts'],
                'tone' => 'tenang dan berimbang',
                'scheduler_enabled' => true, 'research_frequency' => 'daily', 'research_times' => ['06:00'], 'ideas_per_run' => 4,
                'research_source_keys' => ['google_trends', 'google_news', 'reddit', 'youtube', 'rss'],
            ],
            [
                'platform_key' => 'youtube', 'name' => 'Kang Basooki', 'handle' => '@kangbasooki',
                'description' => 'Komentar personal soal kehidupan sehari-hari dan fenomena sosial.',
                'niche' => 'Komentar Personal & Budaya Internet',
                'sub_niches' => ['Kehidupan sehari-hari', 'Fenomena sosial', 'Budaya internet', 'Cerita menarik', 'Humor'],
                'keywords' => ['viral', 'fenomena sosial', 'budaya internet', 'cerita unik'],
                'excluded_keywords' => [],
                'target_audience' => 'Penonton umum Indonesia yang suka konten santai',
                'content_style' => ['Casual commentary', 'Storytelling', 'Opini', 'Hiburan'],
                'content_types' => ['commentary', 'storytelling'], 'content_formats' => ['long_form', 'shorts'],
                'tone' => 'santai',
                'scheduler_enabled' => true, 'research_frequency' => 'daily', 'research_times' => ['09:00'], 'ideas_per_run' => 5,
                'research_source_keys' => ['google_trends', 'youtube', 'reddit', 'google_news', 'web_search'],
            ],
            [
                'platform_key' => 'youtube', 'name' => 'Mr. Off-Peak', 'handle' => '@mroffpeak',
                'description' => 'Berita, topik viral dan isu publik dengan komentar cepat.',
                'niche' => 'Berita & Topik Viral',
                'sub_niches' => ['Berita', 'Topik trending', 'Peristiwa viral', 'Isu sosial Indonesia', 'Berita internasional'],
                'keywords' => ['berita', 'viral', 'trending', 'isu nasional', 'internasional'],
                'excluded_keywords' => ['hoaks'],
                'target_audience' => 'Penonton Indonesia yang mengikuti berita harian',
                'content_style' => ['Fast commentary', 'Analisis berita', 'Opini', 'Satir'],
                'content_types' => ['commentary', 'berita'], 'content_formats' => ['long_form', 'shorts'],
                'tone' => 'cepat dan tajam',
                // The spec's example of a channel that must research several times a day.
                'scheduler_enabled' => true, 'research_frequency' => 'custom',
                'research_times' => ['06:00', '12:00', '18:00', '21:00'], 'ideas_per_run' => 5,
                'research_source_keys' => ['google_trends', 'google_news', 'youtube', 'reddit', 'rss'],
            ],
            [
                'platform_key' => 'youtube', 'name' => 'Movie Script', 'handle' => '@moviescript',
                'description' => 'Alur cerita film, ending explained dan teori film.',
                'niche' => 'Film & Serial',
                'sub_niches' => ['Alur cerita film', 'Ending explained', 'Teori film', 'Analisis karakter', 'Fakta film'],
                'keywords' => ['film', 'movie', 'series', 'ending explained', 'teori film'],
                'excluded_keywords' => ['bocoran ilegal'],
                'target_audience' => 'Penggemar film Indonesia',
                'content_style' => ['Storytelling', 'Movie recap', 'Penjelasan', 'Mystery/twist'],
                'content_types' => ['storytelling', 'recap'], 'content_formats' => ['long_form', 'shorts'],
                'tone' => 'naratif',
                'scheduler_enabled' => true, 'research_frequency' => 'daily', 'research_times' => ['08:00'], 'ideas_per_run' => 4,
                'research_source_keys' => ['google_trends', 'youtube', 'reddit', 'tmdb', 'google_news', 'rss'],
            ],
            [
                'platform_key' => 'youtube', 'name' => 'Fakultas Teknik Unwim', 'handle' => '@ftunwim',
                'description' => 'Konten edukasi teknik sipil, arsitektur dan teknologi bangunan.',
                'niche' => 'Teknik & Pendidikan Teknik',
                'sub_niches' => ['Teknik sipil', 'Arsitektur', 'Konstruksi', 'Teknologi bangunan', 'AutoCAD', 'SketchUp', 'BIM'],
                'keywords' => ['teknik sipil', 'arsitektur', 'konstruksi', 'AutoCAD', 'SketchUp', 'BIM'],
                'excluded_keywords' => [],
                'target_audience' => 'Mahasiswa dan praktisi teknik',
                'content_style' => ['Edukatif', 'Tutorial', 'Akademik', 'Pengetahuan praktis'],
                'content_types' => ['tutorial', 'edukasi'], 'content_formats' => ['long_form'],
                'tone' => 'jelas dan terstruktur',
                'scheduler_enabled' => true, 'research_frequency' => 'daily', 'research_times' => ['06:00'], 'ideas_per_run' => 3,
                'research_source_keys' => ['google_trends', 'youtube', 'web_search', 'reddit', 'google_news', 'rss'],
            ],
            [
                'platform_key' => 'youtube', 'name' => 'Kang Basooki React', 'handle' => '@kangbasookireact',
                'description' => 'Reaksi terhadap video viral, trailer dan konten internet menarik.',
                'niche' => 'Video Viral & Budaya Internet',
                'sub_niches' => ['Video viral', 'Budaya internet', 'Video lucu', 'Trailer film', 'Video teknologi'],
                'keywords' => ['viral', 'trailer', 'reaction', 'video lucu'],
                'excluded_keywords' => [],
                'target_audience' => 'Penonton muda Indonesia',
                'content_style' => ['Reaction', 'Commentary', 'Hiburan'],
                'content_types' => ['reaction'], 'content_formats' => ['long_form', 'shorts'],
                'tone' => 'ekspresif',
                'scheduler_enabled' => true, 'research_frequency' => 'twice_daily', 'research_times' => ['09:00', '19:00'], 'ideas_per_run' => 5,
                'research_source_keys' => ['youtube', 'google_trends', 'reddit', 'rss'],
            ],
            [
                'platform_key' => 'youtube', 'name' => 'Exist Gaming', 'handle' => '@existgaming',
                'description' => 'Game baru, gameplay, tips dan berita gaming.',
                'niche' => 'Gaming',
                'sub_niches' => ['Game baru', 'Game populer', 'Gameplay', 'Tips gaming', 'Walkthrough', 'Berita gaming'],
                'keywords' => ['game', 'gaming', 'gameplay', 'update game', 'rilis game'],
                'excluded_keywords' => ['judi online', 'slot'],
                'target_audience' => 'Gamer Indonesia',
                'content_style' => ['Gameplay', 'Tips & trik', 'Challenge', 'Funny moments', 'Shorts', 'Live'],
                'content_types' => ['gameplay', 'tips'], 'content_formats' => ['long_form', 'shorts', 'live'],
                'tone' => 'seru dan santai',
                'scheduler_enabled' => true, 'research_frequency' => 'daily', 'research_times' => ['07:00'], 'ideas_per_run' => 5,
                'research_source_keys' => ['google_trends', 'youtube', 'reddit', 'steam', 'twitch', 'google_news', 'rss'],
            ],
        ];
    }
}
