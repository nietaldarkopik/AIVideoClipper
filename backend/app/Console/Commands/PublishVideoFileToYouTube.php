<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\Providers\YouTubeProvider;
use Illuminate\Console\Command;

/**
 * Uploads an already-finished video file to a connected YouTube channel using the
 * app's own Google OAuth tokens (YouTubeProvider), with no Project/Clip involved.
 * Meant for external automation (e.g. a scheduled job that renders its own videos)
 * that only needs the "publish to my connected channel" half of the app.
 */
class PublishVideoFileToYouTube extends Command
{
    protected $signature = 'youtube:publish-file
        {video : Path to the video file (MP4)}
        {--account= : social_accounts.id of the connected YouTube channel}
        {--meta= : Path to a JSON file with title, description and tags}
        {--dry-run : Validate everything but do not upload}';

    protected $description = 'Upload a finished video file to a connected YouTube channel';

    public function handle(YouTubeProvider $provider): int
    {
        $video = (string) $this->argument('video');
        if (! is_file($video)) {
            return $this->abort("Video file not found: {$video}");
        }

        $metaPath = (string) $this->option('meta');
        if ($metaPath === '' || ! is_file($metaPath)) {
            return $this->abort('--meta must point to an existing JSON file.');
        }
        $meta = json_decode((string) file_get_contents($metaPath), true);
        if (! is_array($meta) || empty($meta['title'])) {
            return $this->abort('Metadata JSON is invalid or has no "title".');
        }

        $account = SocialAccount::query()
            ->where('platform', 'youtube')
            ->where('status', SocialAccount::STATUS_CONNECTED)
            ->find((int) $this->option('account'));
        if (! $account) {
            return $this->abort('No connected YouTube account with that --account id.');
        }

        $tags = collect($meta['tags'] ?? [])
            ->map(fn ($t) => trim(ltrim((string) $t, '#')))
            ->filter()
            ->unique()
            ->take(12)
            ->values()
            ->all();

        $post = new SocialPost([
            'social_account_id' => $account->id,
            'platform' => 'youtube',
            'title' => (string) $meta['title'],
            'caption' => (string) ($meta['description'] ?? ''),
            'hashtags' => $tags,
        ]);
        $post->setRelation('socialAccount', $account);

        $this->info("Channel : {$account->account_name} ({$account->external_account_id})");
        $this->info('Title   : '.$post->title);
        $this->info('Tags    : '.implode(', ', $tags));

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing uploaded.');

            return self::SUCCESS;
        }

        $result = $provider->publish($post, $video);

        if (! ($result['success'] ?? false)) {
            return $this->abort($result['error'] ?? 'Unknown upload error.');
        }

        $this->line(json_encode([
            'success' => true,
            'post_url' => $result['post_url'] ?? null,
            'video_id' => $result['external_post_id'] ?? null,
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function abort(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
