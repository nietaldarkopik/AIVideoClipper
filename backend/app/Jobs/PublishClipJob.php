<?php

namespace App\Jobs;

use App\Models\ProcessingJob;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\VideoBatchItem;
use App\Services\Social\SocialProviderManager;
use App\Services\Video\CoverGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * One job per (clip, social account) destination — a Facebook failure never blocks
 * the TikTok/Instagram/YouTube jobs for the same clip (spec: platforms fail independently).
 */
class PublishClipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public function __construct(public int $socialPostId) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(SocialProviderManager $manager, CoverGeneratorService $covers): void
    {
        $post = SocialPost::with(['clip', 'socialAccount'])->findOrFail($this->socialPostId);

        Log::info('Publishing clip to social platform', [
            'post_id' => $post->id,
            'clip_id' => $post->clip_id,
            'platform' => $post->platform,
            'social_account_id' => $post->social_account_id,
        ]);

        // Stale delayed job left over from before this post was rescheduled to a
        // LATER time (see SocialPostController::update()) — a fresh delayed job
        // matching the current scheduled_at was already dispatched separately, so
        // this older one must stay quiet instead of publishing early. Combined
        // with the STATUS_PUBLISHED guard below (which covers the opposite
        // direction: rescheduled EARLIER, so the old job fires after the new one
        // already published), reschedules are safe in both directions without any
        // job-cancellation mechanism.
        if ($post->status === SocialPost::STATUS_SCHEDULED && $post->scheduled_at && $post->scheduled_at->isFuture()) {
            Log::info('Publish skipped — superseded by a later reschedule', ['post_id' => $post->id]);

            return;
        }

        // A "Publish Now" override dispatches a fresh, undelayed job for a post
        // that may still have its original staggered/delayed dispatch sitting in
        // the queue — that one will still fire later. Without this guard it would
        // publish the same post to the platform a second time.
        if ($post->status === SocialPost::STATUS_PUBLISHED) {
            Log::info('Publish skipped — already published', ['post_id' => $post->id]);

            return;
        }

        if (! $post->socialAccount || $post->socialAccount->status !== SocialAccount::STATUS_CONNECTED) {
            Log::warning('Publish failed — social account not connected', [
                'post_id' => $post->id,
                'social_account_id' => $post->social_account_id,
            ]);

            $post->update([
                'status' => SocialPost::STATUS_FAILED,
                'error_message' => 'Account is not connected. Reconnect the account and retry.',
            ]);

            return;
        }

        if (! $post->clip || $post->clip->status !== 'completed' || ! $post->clip->output_path) {
            Log::warning('Publish attempted before clip finished rendering', ['post_id' => $post->id]);

            throw new RuntimeException('Clip is not ready to publish yet.');
        }

        $post->update(['status' => SocialPost::STATUS_UPLOADING]);
        $provider = $manager->resolve($post->platform);
        $disk = Storage::disk('media');
        $clipPath = $disk->path($post->clip->output_path);

        // A cover normally already exists by now — RenderClipJob makes one as
        // soon as the video finishes. This is the catch-up path for clips
        // rendered before that existed, or whose cover generation failed then:
        // resolveTemplate() prefers THIS destination channel's configured
        // default, which render time couldn't know. A clip that already has a
        // cover is left alone rather than restyled per channel.
        $clip = $post->clip;
        if (! $clip->cover_path) {
            try {
                $covers->generateForClip($clip, $post->socialAccount);
                // generateForClip() mutates the DB row via $clip->update([...]) but
                // this in-memory $clip object keeps its original null cover_path —
                // reload it so the fallback chain below sees whatever was just written.
                $clip->refresh();
            } catch (Throwable $e) {
                Log::warning('Auto cover generation failed before publish, continuing without one', [
                    'post_id' => $post->id,
                    'clip_id' => $clip->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Primary: custom cover image (CoverGeneratorService output).
        // Fallback 1: plain frame thumbnail (RenderClipJob always generates this).
        // Fallback 2: intro_cover_path (reaction clips only).
        // Publishing should NEVER have a null cover when a fallback exists —
        // platforms that support custom thumbnails get visibly worse results
        // (auto-generated YouTube 3-frame choice, Facebook mid-video frame, ...)
        // when we don't hand them SOMETHING explicit.
        $coverPath = null;
        $coverRelativePath = null;
        if ($clip->cover_path && $disk->exists($clip->cover_path)) {
            $coverPath = $disk->path($clip->cover_path);
            $coverRelativePath = $clip->cover_path;
        } elseif ($clip->thumbnail_path && $disk->exists($clip->thumbnail_path)) {
            $coverPath = $disk->path($clip->thumbnail_path);
            $coverRelativePath = $clip->thumbnail_path;
            Log::info('Publish using thumbnail_path as cover fallback (no custom cover available)', [
                'post_id' => $post->id,
                'clip_id' => $clip->id,
                'platform' => $post->platform,
            ]);
        } elseif ($clip->intro_cover_path && $disk->exists($clip->intro_cover_path)) {
            $coverPath = $disk->path($clip->intro_cover_path);
            $coverRelativePath = $clip->intro_cover_path;
            Log::info('Publish using intro_cover_path as cover fallback (last-resort, reaction clip only)', [
                'post_id' => $post->id,
                'clip_id' => $clip->id,
                'platform' => $post->platform,
            ]);
        }

        $post->update(['status' => SocialPost::STATUS_PUBLISHING]);
        $result = $provider->publish($post, $clipPath, $coverPath);

        if (! $result['success']) {
            $post->increment('retry_count');
            $post->update([
                'status' => SocialPost::STATUS_RETRYING,
                'error_message' => $result['error'] ?? 'Unknown publishing error.',
            ]);

            Log::warning('Clip publish attempt failed', [
                'post_id' => $post->id,
                'platform' => $post->platform,
                'error' => $result['error'] ?? 'Unknown publishing error.',
                'retry_count' => $post->retry_count,
            ]);

            throw new RuntimeException($result['error'] ?? 'Publish failed.');
        }

        $post->update([
            'status' => SocialPost::STATUS_PUBLISHED,
            'published_at' => now(),
            'post_url' => $result['post_url'] ?? null,
            'external_post_id' => $result['external_post_id'] ?? null,
            'error_message' => null,
            'thumbnail_status' => $result['thumbnail_status'] ?? null,
            'thumbnail_uploaded_at' => ($result['thumbnail_status'] ?? null) === SocialPost::THUMBNAIL_STATUS_UPLOADED ? now() : null,
            'thumbnail_error' => $result['thumbnail_error'] ?? null,
        ]);

        Log::info('Clip published successfully', [
            'post_id' => $post->id,
            'platform' => $post->platform,
            'post_url' => $result['post_url'] ?? null,
        ]);

        // YouTube quirk, not a bug in the upload above: a thumbnail set right after
        // upload can look correct in Studio for a while and then get silently reset
        // to an auto-picked video frame once YouTube's own processing pipeline
        // finishes. There's no webhook for that, so a follow-up job polls and
        // re-applies the thumbnail once the video is actually done — see
        // ReapplyYoutubeThumbnailJob and YouTubeProvider::reapplyThumbnail().
        if ($post->platform === 'youtube' && $coverRelativePath && ($result['external_post_id'] ?? null)) {
            $processingJob = ProcessingJob::create([
                'project_id' => $post->clip->project_id,
                'video_id' => $post->clip->video_id,
                'clip_id' => $post->clip->id,
                'type' => 'youtube_thumbnail',
                'status' => ProcessingJob::STATUS_QUEUED,
                'message' => 'Waiting for YouTube to finish processing before re-applying the thumbnail...',
                'started_at' => now(),
            ]);

            ReapplyYoutubeThumbnailJob::dispatch(
                $post->id, $coverRelativePath, $result['external_post_id'], 1, $processingJob->id
            )->delay(now()->addSeconds(90));
        }

        // Batch autobot publishes are staggered (see ProcessBatchItemJob) and finish
        // long after the batch item itself is marked "completed" — this is what
        // keeps that item's posts_published count live instead of frozen at
        // whatever it was the moment the item stopped scheduling more posts.
        VideoBatchItem::where('project_id', $post->clip->project_id)->increment('posts_published');
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Clip publish permanently failed after retries', [
            'post_id' => $this->socialPostId,
            'error' => $exception?->getMessage() ?? 'Publish failed after retries.',
        ]);

        SocialPost::where('id', $this->socialPostId)->update([
            'status' => SocialPost::STATUS_FAILED,
            'error_message' => $exception?->getMessage() ?? 'Publish failed after retries.',
        ]);
    }
}
