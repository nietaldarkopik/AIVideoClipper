<?php

namespace App\Console\Commands;

use App\Services\Social\SocialMetricsSyncer;
use Illuminate\Console\Command;

/**
 * Refreshes performance metrics for published posts (see SocialMetricsSyncer for
 * the staleness tiers that decide which posts are due).
 *
 * Scheduled hourly from bootstrap/app.php; requires `php artisan schedule:work`
 * to actually be running (start-all.ps1/.bat starts it), same caveat as
 * jobs:reap-stalled and channels:poll.
 */
class SyncSocialMetrics extends Command
{
    protected $signature = 'social:sync-metrics
                            {--limit=200 : Maximum posts to sync in this run}
                            {--post= : Sync a single post by id, ignoring the staleness tiers}';

    protected $description = 'Pull views/likes/comments back from each platform into published posts';

    public function handle(SocialMetricsSyncer $syncer): int
    {
        $query = $syncer->dueQuery()->with(['socialAccount', 'clip']);

        if ($postId = $this->option('post')) {
            // Explicit id wins over the tiers — this is the "I want the numbers
            // for THIS post right now" escape hatch used when debugging.
            $query = $query->newQuery()->whereKey((int) $postId)->with(['socialAccount', 'clip']);
        }

        $posts = $query->limit((int) $this->option('limit'))->get();

        if ($posts->isEmpty()) {
            $this->info('No posts due for a metrics sync.');

            return self::SUCCESS;
        }

        $synced = 0;
        $failed = 0;

        foreach ($posts as $post) {
            $syncer->syncOne($post) ? $synced++ : $failed++;
        }

        $this->info("Synced metrics for {$synced} post(s).");

        if ($failed > 0) {
            // Not a failure exit code: one dead post (deleted on the platform,
            // say) shouldn't make the scheduled run look broken every hour.
            $this->warn("{$failed} post(s) failed — see the log for details.");
        }

        return self::SUCCESS;
    }
}
