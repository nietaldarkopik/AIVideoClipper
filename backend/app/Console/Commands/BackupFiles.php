<?php

namespace App\Console\Commands;

use App\Services\Backup\FileBackupService;
use Illuminate\Console\Command;
use Throwable;

class BackupFiles extends Command
{
    protected $signature = 'backup:files {--gzip : Compress the archive (slower — media files are already-compressed video/images, so this usually only saves a few percent)}';

    protected $description = 'Archive the media disk (source videos, rendered clips, covers, subtitles) into storage/app/backups/files/*.tar';

    public function handle(FileBackupService $service): int
    {
        $gzip = (bool) $this->option('gzip');
        $this->info('Archiving media disk into a .tar'.($gzip ? '.gz' : '').' — this can take a long time for a large media library...');

        try {
            $result = $service->create($gzip);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $sizeGb = round($result['size'] / 1024 / 1024 / 1024, 2);
        $this->info("File backup created: storage/app/backups/{$result['path']} ({$sizeGb} GB)");

        return self::SUCCESS;
    }
}
