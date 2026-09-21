<?php

namespace App\Services\Batch;

use App\Jobs\ProcessVideoBatchJob;
use App\Models\User;
use App\Models\VideoBatch;
use App\Models\VideoBatchItem;
use App\Services\Social\AutoPublishScheduler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Creates a VideoBatch + its VideoBatchItem rows and kicks off the batch autobot
 * pipeline (ProcessVideoBatchJob -> ProcessBatchItemJob -> AutoPublishScheduler).
 * Extracted from VideoBatchController::store() so App\Services\Channel\ChannelWatchPoller
 * can queue an auto-detected upload through the exact same path a manually-submitted
 * batch uses, instead of duplicating the settings-array shape and item-creation loop.
 */
class VideoBatchFactory
{
    /**
     * @param  array<int, string>  $urls
     * @param  array{clip_mode?: string, template_id?: ?int, aspect_ratio?: string, subtitle_language?: string, subtitles_enabled?: bool, publishing_profile_id?: ?int, publish_stagger_min_minutes?: int, publish_stagger_max_minutes?: int}  $settings
     * @param  ?int  $channelWatchId  set only by ChannelWatchPoller — lets a later
     *   fix to the watch's publishing_profile_id find this batch's projects again
     *   (see AutoPublishScheduler::resyncChannelWatchProfile()). Null for a
     *   manually-submitted batch (VideoBatchController::store), same as before
     *   this param existed.
     */
    public function createFromUrls(User $user, array $urls, array $settings, ?string $name = null, ?int $channelWatchId = null): VideoBatch
    {
        $urls = Collection::make($urls)->values();

        $batch = $user->videoBatches()->create([
            'channel_watch_id' => $channelWatchId,
            'name' => $name,
            'status' => VideoBatch::STATUS_PENDING,
            'settings' => [
                'clip_mode' => $settings['clip_mode'] ?? 'top_5',
                'template_id' => $settings['template_id'] ?? null,
                'aspect_ratio' => $settings['aspect_ratio'] ?? '9:16',
                'subtitle_language' => $settings['subtitle_language'] ?? 'en',
                'subtitles_enabled' => $settings['subtitles_enabled'] ?? true,
                'publishing_profile_id' => $settings['publishing_profile_id'] ?? null,
                // Minutes between each clip's publish, per destination — see
                // AutoPublishScheduler. Defaults match its own STAGGER_MIN/MAX_SECONDS.
                'publish_stagger_min_minutes' => $settings['publish_stagger_min_minutes'] ?? AutoPublishScheduler::STAGGER_MIN_SECONDS / 60,
                'publish_stagger_max_minutes' => $settings['publish_stagger_max_minutes'] ?? AutoPublishScheduler::STAGGER_MAX_SECONDS / 60,
            ],
            'total_items' => $urls->count(),
        ]);

        $urls->each(fn ($url, $index) => $batch->items()->create([
            'position' => $index,
            'source_url' => $url,
            'status' => VideoBatchItem::STATUS_PENDING,
        ]));

        Log::info('Video batch created', [
            'batch_id' => $batch->id,
            'user_id' => $user->id,
            'channel_watch_id' => $channelWatchId,
            'item_count' => $urls->count(),
        ]);

        // Same dedicated queue as the manual-batch path — see VideoBatchController::store().
        ProcessVideoBatchJob::dispatch($batch->id)->onQueue('batch-downloads');

        return $batch;
    }
}
