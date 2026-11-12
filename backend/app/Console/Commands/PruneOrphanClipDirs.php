<?php

namespace App\Console\Commands;

use App\Models\Clip;
use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Removes clips/{id}/ and videos/{id}/ folders on the media disk whose DB row no longer exists.
 * Dry-run unless --force is given. Folders touched within the grace window are kept,
 * so a clip/video being created or imported right now is never mistaken for an orphan.
 */
class PruneOrphanClipDirs extends Command
{
    protected $signature = 'clips:prune-orphans
        {--force : Actually delete (default is a dry run)}
        {--only=all : clips, videos or all}
        {--days=7 : Keep folders modified within this many days}
        {--limit=0 : Delete at most this many folders per type (0 = no limit)}';

    protected $description = 'Delete clips/{id} and videos/{id} folders with no matching DB row (dry run by default)';

    public function handle(): int
    {
        $only = (string) $this->option('only');
        $force = (bool) $this->option('force');
        $any = false;

        if (in_array($only, ['all', 'clips'], true)) {
            $this->prune('clips', Clip::query()->pluck('id')->flip(), $force);
            $any = true;
        }

        if (in_array($only, ['all', 'videos'], true)) {
            $this->prune('videos', Video::query()->pluck('id')->flip(), $force);
            $any = true;
        }

        if (! $any) {
            $this->error('--only must be clips, videos or all.');

            return self::FAILURE;
        }

        if (! $force) {
            $this->line('Dry run — re-run with --force to delete.');
        }

        return self::SUCCESS;
    }

    private function prune(string $dir, $existing, bool $force): void
    {
        $disk = Storage::disk('media');
        $cutoff = now()->subDays(max(0, (int) $this->option('days')))->getTimestamp();
        $limit = max(0, (int) $this->option('limit'));
        $root = $disk->path($dir);

        if (! is_dir($root)) {
            return;
        }

        $count = $bytes = $kept = 0;

        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || ! $entry->isDir()) {
                continue;
            }

            $name = $entry->getFilename();

            // Only purely numeric names are ours; anything else is left alone.
            if (! ctype_digit($name) || isset($existing[(int) $name])) {
                continue;
            }

            [$size, $latest] = $this->measure($entry->getPathname());

            if ($latest > $cutoff) {
                $kept++;

                continue;
            }

            if ($limit && $count >= $limit) {
                break;
            }

            $count++;
            $bytes += $size;

            if ($force) {
                $disk->deleteDirectory("{$dir}/{$name}");
            }
        }

        $gb = round($bytes / 1e9, 2);
        $verb = $force ? 'Deleted' : 'Would delete';
        $this->info("[{$dir}] {$verb} {$count} orphan folder(s), {$gb} GB. Kept {$kept} recent one(s).");
    }

    /** @return array{0:int,1:int} total bytes and newest mtime (folder mtime for an empty folder) */
    private function measure(string $path): array
    {
        $size = 0;
        $latest = (int) filemtime($path);

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            $size += $file->getSize();
            $latest = max($latest, $file->getMTime());
        }

        return [$size, $latest];
    }
}
