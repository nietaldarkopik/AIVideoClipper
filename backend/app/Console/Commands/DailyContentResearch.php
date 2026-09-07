<?php

namespace App\Console\Commands;

use App\Models\ContentChannel;
use App\Services\Research\ResearchRunLauncher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches a research run for every channel whose schedule is due.
 *
 * Runs hourly (bootstrap/app.php) rather than at a fixed daily time: each channel
 * carries its own times in its own timezone (Mr. Off-Peak at 06:00/12:00/18:00/
 * 21:00, another at 06:00 only), so the command's job is to ask "who is due now",
 * not to be the schedule itself. That is what keeps schedules configurable from
 * the UI with no code or cron change.
 */
class DailyContentResearch extends Command
{
    protected $signature = 'research:daily
                            {--channel= : Only this channel id, ignoring its schedule}
                            {--force : Dispatch every enabled channel regardless of whether it is due}';

    protected $description = 'Dispatch content research runs for channels whose schedule is due';

    public function handle(ResearchRunLauncher $launcher): int
    {
        $now = CarbonImmutable::now();
        $channelId = $this->option('channel');
        $force = (bool) $this->option('force');

        $query = ContentChannel::query()->where('is_active', true);

        if ($channelId !== null) {
            $query->where('id', (int) $channelId);
        } else {
            $query->where('scheduler_enabled', true);
        }

        $dispatched = 0;
        $skipped = 0;
        $failed = 0;

        Log::info('research.scheduler.start', ['at' => $now->toIso8601String(), 'force' => $force]);

        $query->chunkById(50, function ($channels) use ($launcher, $now, $force, $channelId, &$dispatched, &$skipped, &$failed) {
            foreach ($channels as $channel) {
                /** @var ContentChannel $channel */
                $due = $force || $channelId !== null || $channel->isDue($now);

                // A channel whose scheduler was just enabled has no next_research_at yet.
                // Stamp it so it joins the schedule from its next slot rather than firing
                // immediately at whatever hour it happened to be enabled.
                if (! $due && $channel->scheduler_enabled && $channel->next_research_at === null) {
                    $launcher->stampSchedule($channel, $now);
                    $skipped++;

                    continue;
                }

                if (! $due) {
                    $skipped++;

                    continue;
                }

                try {
                    $run = $launcher->launch($channel, $channelId !== null && ! $force ? 'manual' : 'scheduled');
                    $dispatched++;
                    $this->line("Dispatched run #{$run->id} for channel [{$channel->name}].");
                } catch (Throwable $e) {
                    // One channel failing to dispatch (already running, DB hiccup) must not
                    // stop the rest of the loop — spec rule 16.
                    $failed++;
                    $this->warn("Channel [{$channel->name}] dilewati: {$e->getMessage()}");
                    Log::warning('research.scheduler.channel_skipped', [
                        'channel_id' => $channel->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        Log::info('research.scheduler.finish', ['dispatched' => $dispatched, 'skipped' => $skipped, 'failed' => $failed]);

        $this->info("Dispatched {$dispatched}, skipped {$skipped}, failed {$failed}.");

        return self::SUCCESS;
    }
}
