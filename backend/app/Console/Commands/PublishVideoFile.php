<?php

namespace App\Console\Commands;

use App\Jobs\PublishClipJob;
use App\Models\Clip;
use App\Models\Project;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Publishes a finished MP4 made elsewhere (e.g. the animasi2d cartoon maker) through the normal publish pipeline:
 * it is registered as a rendered Clip, then PublishClipJob uploads it to the chosen connected account.
 */
class PublishVideoFile extends Command
{
    protected $signature = 'clips:publish-file
        {file : Path of the finished MP4}
        {--account= : Social account id (see social_accounts)}
        {--title= : Video title (default: file name)}
        {--caption= : Description}
        {--tags= : Comma separated hashtags}
        {--privacy=private : private | unlisted | public}
        {--user=1 : Owner user id}
        {--dry-run : Register nothing and upload nothing, only show what would happen}';

    protected $description = 'Upload a finished MP4 to a connected social account (YouTube) as a clip';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("File tidak ditemukan: $file");

            return self::FAILURE;
        }
        $privacy = (string) $this->option('privacy');
        if (! in_array($privacy, ['private', 'unlisted', 'public'], true)) {
            $this->error('--privacy harus private, unlisted, atau public.');

            return self::FAILURE;
        }
        $userId = (int) $this->option('user');
        $account = SocialAccount::where('user_id', $userId)->find((int) $this->option('account'));
        if (! $account || $account->status !== SocialAccount::STATUS_CONNECTED) {
            $this->error('Akun sosial tidak ditemukan / belum terhubung (pakai --account=ID).');

            return self::FAILURE;
        }

        $title = trim((string) $this->option('title')) ?: pathinfo($file, PATHINFO_FILENAME);
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('tags')))));
        $this->line("Akun    : {$account->account_name} ({$account->platform}) #{$account->id}");
        $this->line("Judul   : $title");
        $this->line("Privasi : $privacy");
        $this->line('Ukuran  : '.round(filesize($file) / 1048576, 1).' MB');
        if ($this->option('dry-run')) {
            $this->info('Dry-run: tidak ada yang dibuat atau diunggah.');

            return self::SUCCESS;
        }

        $disk = Storage::disk('media');
        $key = Str::uuid()->toString();
        $video = $disk->putFileAs('imported', new \Illuminate\Http\File($file), "$key.mp4");
        $thumb = "imported/$key.jpg";
        $ffmpeg = env('FFMPEG_BIN', 'ffmpeg');
        $duration = (float) trim((string) Process::run([env('FFPROBE_BIN', 'ffprobe'), '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $file])->output());
        Process::run([$ffmpeg, '-y', '-ss', (string) min(3, max(0, $duration / 4)), '-i', $file, '-frames:v', '1', '-vf', 'scale=1280:-2', $disk->path($thumb)]);

        $project = Project::create(['user_id' => $userId, 'title' => $title, 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $vid = Video::create(['project_id' => $project->id, 'source_type' => 'upload', 'original_filename' => basename($file), 'disk_path' => $video, 'title' => $title, 'duration_seconds' => (int) round($duration), 'status' => 'ready']);
        $clip = Clip::create([
            'project_id' => $project->id, 'video_id' => $vid->id, 'title' => $title, 'caption' => $this->option('caption'), 'hashtags' => $tags,
            'start_time' => 0, 'end_time' => $duration, 'duration' => $duration, 'aspect_ratio' => '16:9',
            'status' => Clip::STATUS_COMPLETED, 'progress' => 100, 'output_path' => $video, 'output_size_bytes' => filesize($file), 'rendered_at' => now(),
            'thumbnail_path' => $disk->exists($thumb) ? $thumb : null, 'cover_path' => $disk->exists($thumb) ? $thumb : null,
        ]);
        $post = SocialPost::create([
            'clip_id' => $clip->id, 'social_account_id' => $account->id, 'platform' => $account->platform,
            'title' => $title, 'caption' => $this->option('caption'), 'hashtags' => $tags, 'status' => SocialPost::STATUS_READY,
        ]);

        // runs here (not on a queue worker) so the privacy chosen above is the one the provider reads
        config(['services.google.default_privacy' => $privacy]);
        $this->line("Mengunggah… (clip #{$clip->id}, post #{$post->id})");
        PublishClipJob::dispatchSync($post->id);

        $post->refresh();
        $this->line('Status  : '.$post->status);
        foreach (['external_post_id', 'post_url', 'error_message'] as $f) {
            if (! empty($post->{$f})) {
                $this->line("$f : ".$post->{$f});
            }
        }

        return $post->status === SocialPost::STATUS_PUBLISHED ? self::SUCCESS : self::FAILURE;
    }
}
