<?php

namespace App\Services\Channel;

use App\Models\ChannelWatch;
use App\Models\VideoBatchItem;
use App\Services\Batch\VideoBatchFactory;
use Illuminate\Support\Facades\Log;
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
    ) {}

    public function pollOne(ChannelWatch $watch): void
    {
        Log::info('Channel watch: scanning channel for new uploads', [
            'channel_watch_id' => $watch->id,
            'channel_id' => $watch->channel_id,
            'channel_title' => $watch->channel_title,
            'since' => optional($watch->last_video_published_at)->toIso8601String(),
        ]);

        try {
            if (! $watch->uploads_playlist_id) {
                throw new RuntimeException('Channel is missing its uploads playlist — try removing and re-adding it.');
            }

            $videos = $this->monitor->fetchNewUploads($watch->uploads_playlist_id, $watch->last_video_published_at);
        } catch (Throwable $e) {
            Log::warning('Channel watch: scan failed', [
                'channel_watch_id' => $watch->id,
                'error' => $e->getMessage(),
            ]);

            $watch->update(['last_error' => $e->getMessage(), 'last_checked_at' => now()]);

            return;
        }

        Log::info('Channel watch: scan complete', [
            'channel_watch_id' => $watch->id,
            'new_videos_found' => count($videos),
        ]);

        foreach ($videos as $video) {
            // Belt-and-braces against re-importing something already taken in.
            // The watermark is supposed to make this impossible, but it's a
            // single timestamp comparison against a value that has to survive a
            // round trip through the database — one timezone slip there (which
            // is exactly what happened) turns every poll into a re-download of
            // the same uploads, with a fresh project and a fresh 700MB file each
            // time. This check is cheap and doesn't care WHY a duplicate slipped
            // through. The watermark still advances below, so a skipped video
            // stops being reconsidered instead of being re-examined forever.
            if ($this->alreadyImported($watch, $video['url'])) {
                Log::info('Channel watch: video already imported, skipping', [
                    'channel_watch_id' => $watch->id,
                    'video_id' => $video['video_id'],
                    'video_url' => $video['url'],
                ]);

                $watch->update([
                    'last_video_id' => $video['video_id'],
                    'last_video_published_at' => $video['published_at'],
                ]);

                continue;
            }

            try {
                $batch = $this->batchFactory->createFromUrls(
                    $watch->user,
                    [$video['url']],
                    $watch->settings ?? [],
                    "Auto: {$watch->channel_title}",
                    $watch->id,
                );

                Log::info('Channel watch: video queued into batch', [
                    'channel_watch_id' => $watch->id,
                    'video_id' => $video['video_id'],
                    'video_url' => $video['url'],
                    'published_at' => $video['published_at']->toIso8601String(),
                    'batch_id' => $batch->id,
                ]);
            } catch (Throwable $e) {
                // Leave later (newer) videos for the next poll rather than processing
                // them out of order — the watermark stays right before this video so
                // it's retried, not skipped, next time.
                Log::error('Channel watch: failed to queue video', [
                    'channel_watch_id' => $watch->id,
                    'video_id' => $video['video_id'],
                    'error' => $e->getMessage(),
                ]);

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

    /**
     * Has this URL already been taken into this user's library, in any state?
     *
     * Checks batch ITEMS rather than finished Videos on purpose: an item exists
     * from the moment a batch is created, so a video still downloading — or one
     * whose download failed — counts as already imported. Otherwise a poll
     * landing mid-download would queue the same video a second time, and a
     * repeatedly-failing video would be retried forever on every poll.
     * Scoped to the watch's own user so two users watching the same channel
     * still each get their own copy.
     */
    private function alreadyImported(ChannelWatch $watch, string $url): bool
    {
        return VideoBatchItem::where('source_url', $url)
            ->whereHas('videoBatch', fn ($q) => $q->where('user_id', $watch->user_id))
            ->exists();
    }

    /**
     * Queues a channel's current single latest upload immediately, then sets the
     * watermark to it — called right after a watch is created (see
     * ChannelWatchController::store()) so adding a channel visibly does something
     * right away instead of silently waiting for its next organic upload, which
     * can be days off. Deliberately queues only the one latest video, never the
     * whole recent history fetchNewUploads() can return: the point is "you can
     * see it's working," not a surprise back-catalog dump. A channel with no
     * uploads yet (or none passing the Shorts/duration filter already applied by
     * fetchNewUploads()) is a no-op — the watch just starts empty and picks up
     * the channel's actual next upload via the normal scheduled poll.
     */
    public function queueLatestVideo(ChannelWatch $watch): void
    {
        Log::info('Channel watch: scanning channel for its current latest upload', [
            'channel_watch_id' => $watch->id,
            'channel_id' => $watch->channel_id,
            'channel_title' => $watch->channel_title,
        ]);

        if (! $watch->uploads_playlist_id) {
            Log::warning('Channel watch: no uploads playlist, skipping initial scan', [
                'channel_watch_id' => $watch->id,
            ]);

            return;
        }

        try {
            $videos = $this->monitor->fetchNewUploads($watch->uploads_playlist_id, null);
        } catch (Throwable $e) {
            Log::warning('Channel watch: initial scan failed', [
                'channel_watch_id' => $watch->id,
                'error' => $e->getMessage(),
            ]);

            $watch->update(['last_error' => $e->getMessage()]);

            return;
        }

        if (empty($videos)) {
            Log::info('Channel watch: no qualifying uploads found on initial scan', [
                'channel_watch_id' => $watch->id,
            ]);

            return;
        }

        // fetchNewUploads() sorts ascending by published_at, so the last element
        // is the most recent upload.
        $latest = $videos[array_key_last($videos)];

        try {
            $batch = $this->batchFactory->createFromUrls(
                $watch->user,
                [$latest['url']],
                $watch->settings ?? [],
                "Auto: {$watch->channel_title}",
                $watch->id,
            );

            Log::info('Channel watch: latest video queued into batch', [
                'channel_watch_id' => $watch->id,
                'video_id' => $latest['video_id'],
                'video_url' => $latest['url'],
                'published_at' => $latest['published_at']->toIso8601String(),
                'batch_id' => $batch->id,
            ]);
        } catch (Throwable $e) {
            Log::error('Channel watch: failed to queue latest video', [
                'channel_watch_id' => $watch->id,
                'video_id' => $latest['video_id'],
                'error' => $e->getMessage(),
            ]);

            $watch->update(['last_error' => $e->getMessage()]);

            return;
        }

        $watch->update([
            'last_video_id' => $latest['video_id'],
            'last_video_published_at' => $latest['published_at'],
        ]);
    }
}
