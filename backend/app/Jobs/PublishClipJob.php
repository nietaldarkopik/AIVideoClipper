<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\SocialProviderManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

        if (! $post->socialAccount || $post->socialAccount->status !== SocialAccount::STATUS_CONNECTED) {
            $post->update([
                'status' => SocialPost::STATUS_FAILED,
                'error_message' => 'Account is not connected. Reconnect the account and retry.',
            ]);

            return;
        }

        if (! $post->clip || $post->clip->status !== 'completed' || ! $post->clip->output_path) {
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

            throw new RuntimeException($result['error'] ?? 'Publish failed.');
        }

        $post->update([
            'status' => SocialPost::STATUS_PUBLISHED,
            'published_at' => now(),
            'post_url' => $result['post_url'] ?? null,
            'external_post_id' => $result['external_post_id'] ?? null,
            'error_message' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        SocialPost::where('id', $this->socialPostId)->update([
            'status' => SocialPost::STATUS_FAILED,
            'error_message' => $exception?->getMessage() ?? 'Publish failed after retries.',
        ]);
    }
}
