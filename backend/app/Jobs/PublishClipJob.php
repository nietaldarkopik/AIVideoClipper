<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\VideoBatchItem;
use App\Services\Social\SocialProviderManager;
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

    public function __construct(public int $socialPostId)
    {
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(SocialProviderManager $manager): void
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
        $clipPath = Storage::disk('media')->path($post->clip->output_path);

        $post->update(['status' => SocialPost::STATUS_PUBLISHING]);
        $result = $provider->publish($post, $clipPath);

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
        ]);

        Log::info('Clip published successfully', [
            'post_id' => $post->id,
            'platform' => $post->platform,
            'post_url' => $result['post_url'] ?? null,
        ]);

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
