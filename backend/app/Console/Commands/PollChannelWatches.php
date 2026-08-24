<?php

namespace App\Console\Commands;

use App\Models\ChannelWatch;
use App\Services\Channel\ChannelWatchPoller;
use Illuminate\Console\Command;

/**
 * Checks every active ChannelWatch for uploads newer than its watermark and
 * queues each new one as a VideoBatch (see ChannelWatchPoller — the same logic
 * backs ChannelWatchController::checkNow for an on-demand check).
 *
 * Scheduled every 15 minutes from bootstrap/app.php; requires `php artisan
 * schedule:work` to actually be running (start-all.ps1/.bat starts it), same
 * caveat as jobs:reap-stalled.
 */
class PollChannelWatches extends Command
{
    protected $signature = 'channels:poll';

    protected $description = 'Check watched YouTube channels for new uploads and auto-queue them as batches';

    public function handle(ChannelWatchPoller $poller): int
    {
        $checked = 0;

        ChannelWatch::where('is_active', true)->chunkById(50, function ($watches) use ($poller, &$checked) {
            foreach ($watches as $watch) {
                $poller->pollOne($watch);
                $checked++;
            }
        });

        $this->info("Checked {$checked} channel(s).");

        return self::SUCCESS;
    }
}
