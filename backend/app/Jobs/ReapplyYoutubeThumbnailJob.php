<?php

namespace App\Jobs;

use App\Models\ProcessingJob;
use App\Models\SocialPost;
use App\Services\Social\Providers\YouTubeProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Guards against a real YouTube quirk, not a bug in the upload itself: setting a
 * custom thumbnail (thumbnails.set) works and shows correctly in Studio right
 * after upload, but YouTube's own transcode/analysis pipeline keeps running in
 * the background afterward — and once THAT finishes, it can silently reset the
 * thumbnail to an auto-picked video frame. There is no webhook for "processing
 * finished", so this job polls with backoff and re-applies the thumbnail once the
 * video is actually done, which is the point past which YouTube stops touching it.
 *
 * Dispatched once by PublishClipJob right after a successful YouTube publish that
 * had a cover image; re-dispatches itself (not queue retries — tries=1) as long
 * as the video is still processing, up to a bounded number of checks.
 */
class ReapplyYoutubeThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Seconds to wait before each successive check, indexed by attempt number
     * (1-based). Typical short-clip processing finishes well within this window;
     * the later, longer gaps cover slower processing during YouTube's busy
     * periods. After the last one, whatever thumbnail YouTube settled on is left
     * alone rather than polled forever.
     */
    private const DELAYS_SECONDS = [90, 150, 300, 300, 300];

    public function __construct(
        public int $socialPostId,
        public string $coverRelativePath,
        public string $videoId,
        public int $attempt = 1,
        // Tracks this run in the admin Processing tab so the raw YouTube
        // request/response is inspectable there instead of only in the Laravel
        // log. Nullable — older queued jobs from before this existed still
        // deserialize fine and simply skip tracking.
        public ?int $processingJobId = null,
    ) {
    }

    public function handle(YouTubeProvider $provider): void
    {
        $processingJob = $this->processingJobId ? ProcessingJob::find($this->processingJobId) : null;
        $processingJob?->markRunning("Checking YouTube video processing status (attempt {$this->attempt})...");

        $post = SocialPost::with('socialAccount')->find($this->socialPostId);

        // Nothing to reapply to if the post is gone, the account was disconnected,
        // or the post somehow never actually ended up published.
        if (! $post || ! $post->socialAccount || $post->status !== SocialPost::STATUS_PUBLISHED) {
            $processingJob?->markFailed('Social post is missing, unpublished, or its account was disconnected.');

            return;
        }

        $coverPath = Storage::disk('media')->path($this->coverRelativePath);

        if (! file_exists($coverPath)) {
            Log::warning('ReapplyYoutubeThumbnailJob: cover file no longer exists, giving up', [
                'post_id' => $post->id, 'video_id' => $this->videoId,
            ]);

            $processingJob?->markFailed('Cover image file no longer exists on disk: '.$this->coverRelativePath);

            return;
        }

        $result = $provider->reapplyThumbnail($post->socialAccount, $this->videoId, $coverPath);

        if (isset($result['request'])) {
            $processingJob?->recordApiCall($result['request'], $result['response'] ?? null);
        }

        if (! $result['done']) {
            $processingJob?->markProgress(
                min(90, (int) round(($this->attempt / count(self::DELAYS_SECONDS)) * 90)),
                'Video still processing on YouTube — will check again shortly.'
            );

            $this->scheduleNextAttempt();

            return;
        }

        $post->update([
            'thumbnail_status' => $result['success'] ? SocialPost::THUMBNAIL_STATUS_UPLOADED : SocialPost::THUMBNAIL_STATUS_FAILED,
            'thumbnail_uploaded_at' => $result['success'] ? now() : $post->thumbnail_uploaded_at,
            'thumbnail_error' => $result['success'] ? null : ($result['error'] ?? 'Thumbnail re-apply failed.'),
        ]);

        if ($result['success']) {
            $processingJob?->markCompleted('Thumbnail re-applied successfully.');
        } else {
            $processingJob?->markFailed($result['error'] ?? 'Thumbnail re-apply failed.');
        }

        Log::info('ReapplyYoutubeThumbnailJob: video finished processing, thumbnail re-applied', [
            'post_id' => $post->id, 'video_id' => $this->videoId, 'success' => $result['success'],
        ]);
    }

    private function scheduleNextAttempt(): void
    {
        // DELAYS_SECONDS[0] is the wait BEFORE attempt 1 (used by the initial
        // dispatch in PublishClipJob), so the wait before attempt N+1 is
        // DELAYS_SECONDS[N] — i.e. indexed by the CURRENT (about-to-finish) attempt
        // number, not attempt-1.
        if ($this->attempt >= count(self::DELAYS_SECONDS)) {
            Log::warning('ReapplyYoutubeThumbnailJob: video still processing after every attempt, giving up', [
                'post_id' => $this->socialPostId, 'video_id' => $this->videoId,
            ]);

            SocialPost::where('id', $this->socialPostId)->update([
                'thumbnail_status' => SocialPost::THUMBNAIL_STATUS_FAILED,
                'thumbnail_error' => 'YouTube video was still processing after every re-apply attempt; thumbnail could not be confirmed.',
            ]);

            if ($this->processingJobId) {
                ProcessingJob::where('id', $this->processingJobId)->first()?->markFailed(
                    'YouTube video was still processing after every re-apply attempt; thumbnail could not be confirmed.'
                );
            }

            return;
        }

        self::dispatch($this->socialPostId, $this->coverRelativePath, $this->videoId, $this->attempt + 1, $this->processingJobId)
            ->delay(now()->addSeconds(self::DELAYS_SECONDS[$this->attempt]));
    }
}
