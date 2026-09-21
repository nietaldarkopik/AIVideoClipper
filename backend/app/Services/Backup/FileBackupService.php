<?php

namespace App\Services\Backup;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Archives the whole `media` disk (source videos, rendered clips, covers,
 * subtitles, template assets — see config/filesystems.php) into a single .tar
 * for moving to another server. CLI-only by design — this disk runs well into
 * the hundreds of GB in practice, which is both too slow to zip losslessly
 * (video is already compressed; re-compressing just burns CPU for a few
 * percent) and too large to round-trip through a browser download, so there is
 * no admin-UI equivalent of DatabaseBackupService's download button. Meant to
 * be run directly on the server (SSH/RDP), with the resulting .tar moved to
 * the new server via scp/rsync/external drive and extracted with
 * `php artisan restore:files`.
 */
class FileBackupService
{
    public function __construct(
        private readonly FilesystemAdapter $backupsDisk,
        private readonly FilesystemAdapter $mediaDisk,
        private readonly string $tarBin,
    ) {}

    /**
     * @return array{filename: string, path: string, size: int}
     */
    public function create(bool $gzip = false): array
    {
        $sourceRoot = $this->mediaDisk->path('');
        if (! is_dir($sourceRoot)) {
            throw new RuntimeException("Media disk root does not exist: {$sourceRoot}");
        }

        $extension = $gzip ? 'tar.gz' : 'tar';
        $filename = 'media_'.now()->format('Y-m-d_His').'.'.$extension;
        $this->backupsDisk->makeDirectory('files');
        $fullPath = $this->backupsDisk->path("files/{$filename}");

        // -C into the media root first so the archive's internal paths are
        // relative (covers/…, clips/…) rather than baking in this machine's
        // absolute storage path — restore:files extracts straight back onto
        // another server's own media disk root regardless of where either
        // machine keeps its Laravel install.
        $args = [$this->tarBin, $gzip ? '-czf' : '-cf', $fullPath, '-C', $sourceRoot, '.'];

        // No timeout — a 100GB+ archive can legitimately take hours; this is
        // meant to be run directly at a terminal (or under nohup/a scheduled
        // task), not from an HTTP request.
        $result = Process::timeout(0)->run($args);

        if (! $result->successful()) {
            $this->backupsDisk->delete("files/{$filename}");

            throw new RuntimeException('tar failed: '.$result->errorOutput());
        }

        return [
            'filename' => $filename,
            'path' => "files/{$filename}",
            'size' => $this->backupsDisk->size("files/{$filename}"),
        ];
    }

    /**
     * @return array<int, array{filename: string, size: int, created_at: string}>
     */
    public function list(): array
    {
        if (! $this->backupsDisk->exists('files')) {
            return [];
        }

        return collect($this->backupsDisk->files('files'))
            ->filter(fn ($path) => Str::endsWith($path, ['.tar', '.tar.gz']))
            ->map(fn ($path) => [
                'filename' => basename($path),
                'size' => $this->backupsDisk->size($path),
                'created_at' => date('c', $this->backupsDisk->lastModified($path)),
            ])
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    /**
     * Extracts an archive back onto the media disk root. Existing files with
     * the same relative path are overwritten (tar's default) — meant for
     * populating an EMPTY media disk on a freshly migrated server, not for
     * merging into one that already has other data.
     */
    public function restore(string $filename): void
    {
        $filename = basename($filename);
        $sourcePath = $this->backupsDisk->path("files/{$filename}");
        if (! is_file($sourcePath)) {
            throw new RuntimeException("Backup archive not found: {$filename}");
        }

        $destRoot = $this->mediaDisk->path('');
        if (! is_dir($destRoot)) {
            mkdir($destRoot, 0755, true);
        }

        $result = Process::timeout(0)->run([$this->tarBin, '-xf', $sourcePath, '-C', $destRoot]);

        if (! $result->successful()) {
            throw new RuntimeException('tar extraction failed: '.$result->errorOutput());
        }
    }
}
