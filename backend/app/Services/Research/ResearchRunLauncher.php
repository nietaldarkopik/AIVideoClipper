<?php

namespace App\Services\Research;

use App\Jobs\ResearchChannelJob;
use App\Models\ContentChannel;
use App\Models\ResearchRun;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Creates a ResearchRun and queues its job.
 *
 * Shared by the scheduler command and the manual "Run now" endpoint so both paths
 * produce identical run records — the previous shape of this app's channel-watch
 * feature had the same requirement (ChannelWatchPoller backs both), and splitting
 * it produced two subtly different code paths.
 */
class ResearchRunLauncher
{
    /**
     * @param  'scheduled'|'manual'  $trigger
     *
     * @throws RuntimeException when the channel already has a run in flight
     */
    public function launch(ContentChannel $channel, string $trigger = 'manual', ?int $userId = null): ResearchRun
    {
        if ($this->hasRunInFlight($channel)) {
            throw new RuntimeException('Riset untuk channel ini sedang berjalan.');
        }

        $run = ResearchRun::create([
            'content_channel_id' => $channel->id,
            'user_id' => $userId ?? $channel->user_id,
            'trigger' => $trigger,
            'status' => ResearchRun::STATUS_RUNNING,
            'progress' => 0,
            'message' => 'Menunggu antrean...',
        ]);

        // Stamped before dispatch, not after the run finishes: the scheduler picks
        // channels by next_research_at, and a long run would otherwise be re-picked
        // on the next tick and dispatched twice.
        $this->stampSchedule($channel);

        ResearchChannelJob::dispatch($run->id);

        return $run;
    }

    public function hasRunInFlight(ContentChannel $channel): bool
    {
        return $channel->researchRuns()
            ->where('status', ResearchRun::STATUS_RUNNING)
            // A run whose worker died without calling failed() would block the channel
            // forever; anything still RUNNING after twice the job timeout is treated as
            // abandoned rather than in flight.
            ->where('created_at', '>=', now()->subMinutes(30))
            ->exists();
    }

    /**
     * Advances last/next research timestamps. Also used when the scheduler settings
     * change, so an edited schedule takes effect immediately.
     */
    public function stampSchedule(ContentChannel $channel, ?CarbonImmutable $now = null): void
    {
        $now = $now ?? CarbonImmutable::now();

        $channel->forceFill([
            'last_research_at' => $now,
            'next_research_at' => $channel->scheduler_enabled ? $channel->nextRunAfter($now) : null,
        ])->save();
    }
}
