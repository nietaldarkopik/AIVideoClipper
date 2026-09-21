<?php

namespace App\Console\Commands;

use App\Jobs\PublishClipJob;
use App\Models\Clip;
use App\Models\SocialPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-dispatches PublishClipJob for scheduled posts whose slot has already
 * passed without publishing.
 *
 * Scheduling relies entirely on a delayed queue job (AutoPublishScheduler
 * dispatches PublishClipJob(...)->delay($slot) once, at schedule time) — there
 * is no database record of "this should have fired by now", so a delayed job
 * lost by the queue driver (e.g. a Redis restart with no AOF persistence,
 * wiping anything not yet flushed to an RDB snapshot) leaves the SocialPost
 * sitting at STATUS_SCHEDULED forever with nothing to notice, exactly the way
 * ReapStuckClipRenders' target clips do for the same underlying reason.
 *
 * A post whose clip isn't rendered yet is deliberately left alone this run —
 * PublishClipJob itself throws and retries when the clip isn't ready (see its
 * "Clip is not ready to publish yet" check), so dispatching early would just
 * burn its 4 tries against a clip that legitimately needs more time. The clip
 * side of this same failure class is ReapStuckClipRenders' job; once that
 * clip finishes rendering, the NEXT run of this command picks the post back up.
 *
 * Scheduled every 10 minutes from bootstrap/app.php; requires `php artisan
 * schedule:work` to actually be running, same caveat as jobs:reap-stalled.
 */
class RepublishOverdueSocialPosts extends Command
{
    protected $signature = 'social:republish-overdue {--minutes= : How late a post must be before it is considered overdue}';

    protected $description = 'Re-dispatch PublishClipJob for scheduled posts whose slot passed without publishing';

    public function handle(): int
    {
        // A shorter grace period than the clip/processing-job stall threshold on
        // purpose: a lost delayed job is invisible the instant its slot passes,
        // there is no in-progress state to wait out the way a render has, so
        // there is nothing to gain by waiting as long as jobs:reap-stalled does.
        $minutes = (int) ($this->option('minutes') ?? config('services.processing.publish_grace_minutes', 10));

        $overdue = SocialPost::query()
            ->where('status', SocialPost::STATUS_SCHEDULED)
            ->where('scheduled_at', '<', now()->subMinutes($minutes))
            ->with('clip')
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('No overdue scheduled posts found.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        $waiting = 0;

        foreach ($overdue as $post) {
            if (! $post->clip || $post->clip->status !== Clip::STATUS_COMPLETED) {
                $waiting++;

                continue;
            }

            PublishClipJob::dispatch($post->id);
            $dispatched++;

            Log::warning('Re-dispatched an overdue scheduled post whose publish job was lost', [
                'social_post_id' => $post->id,
                'scheduled_at' => $post->scheduled_at,
                'platform' => $post->platform,
            ]);

            $this->warn("Re-dispatched post #{$post->id} ({$post->platform}), overdue since {$post->scheduled_at}.");
        }

        $this->info("Dispatched {$dispatched} overdue post(s).");

        if ($waiting > 0) {
            // Not a failure: these are exactly the ones ReapStuckClipRenders is
            // responsible for — this command will pick them back up automatically
            // once their clip finishes.
            $this->line("{$waiting} more are overdue but still waiting on their clip to finish rendering.");
        }

        return self::SUCCESS;
    }
}
