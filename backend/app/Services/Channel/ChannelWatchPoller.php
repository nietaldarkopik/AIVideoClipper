<?php

namespace App\Services\Channel;

use App\Models\ChannelWatch;
use App\Services\Batch\VideoBatchFactory;
use RuntimeException;
use Throwable;

/**
 * Checks one ChannelWatch for uploads newer than its watermark and queues each
 * as a VideoBatch via VideoBatchFactory — the same pipeline a manually-submitted
 * batch uses (download -> transcribe/analyze -> auto-pick clips -> render ->
 * auto-publish). Single source of truth shared by the scheduled
 * `channels:poll` command and ChannelWatchController::checkNow, so a manual
 * "check now" click behaves identically to the background poll.
 *
 * Only real YouTube channels are supported today; $watch->platform is kept
 * string-typed (matching TrendingItem/SocialAccount elsewhere in the app) for
 * when another platform's provider is added.
 */
class ChannelWatchPoller
{
    public function __construct(
        private readonly YouTubeChannelMonitor $monitor,
        private readonly VideoBatchFactory $batchFactory,
    ) {
    }

    public function pollOne(ChannelWatch $watch): void
    {
        try {
            if (! $watch->uploads_playlist_id) {
                throw new RuntimeException('Channel is missing its uploads playlist — try removing and re-adding it.');
            }

            $videos = $this->monitor->fetchNewUploads($watch->uploads_playlist_id, $watch->last_video_published_at);
        } catch (Throwable $e) {
            $watch->update(['last_error' => $e->getMessage(), 'last_checked_at' => now()]);

            return;
        }

        foreach ($videos as $video) {
            try {
                $this->batchFactory->createFromUrls(
                    $watch->user,
                    [$video['url']],
                    $watch->settings ?? [],
                    "Auto: {$watch->channel_title}",
                );
            } catch (Throwable $e) {
                // Leave later (newer) videos for the next poll rather than processing
                // them out of order — the watermark stays right before this video so
                // it's retried, not skipped, next time.
                $watch->update(['last_error' => $e->getMessage(), 'last_checked_at' => now()]);

                return;
            }

            // Advance strictly per-video-turned-into-a-batch: a video is only ever
            // marked "seen" once it's actually been queued.
            $watch->update([
                'last_video_id' => $video['video_id'],
                'last_video_published_at' => $video['published_at'],
            ]);
        }

        $watch->update(['last_checked_at' => now(), 'last_error' => null]);
    }
}
