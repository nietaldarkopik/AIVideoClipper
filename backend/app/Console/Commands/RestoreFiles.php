<?php

namespace App\Console\Commands;

use App\Services\Backup\FileBackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Extracts a backup:files archive back onto the media disk root. Meant for a
 * freshly migrated server whose media disk is empty — files that already
 * exist at the same relative path are overwritten (tar's default), so this
 * isn't a safe "merge" operation against a media disk with other data on it.
 */
class RestoreFiles extends Command
{
    protected $signature = 'restore:files {filename : File name inside storage/app/backups/files, e.g. media_2026-09-07_120000.tar}';

    protected $description = 'Extract a backup:files archive back onto the media disk';

    public function handle(FileBackupService $service): int
    {
        $filename = $this->argument('filename');
        $this->info("Extracting {$filename} onto the media disk — this can take a long time for a large archive...");

        try {
            $service->restore($filename);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('File restore complete.');

        return self::SUCCESS;
    }
}
