<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseBackupService;
use Illuminate\Console\Command;
use Throwable;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Dump the PostgreSQL database to storage/app/backups/database (plain SQL, via pg_dump)';

    public function handle(DatabaseBackupService $service): int
    {
        $this->info('Running pg_dump...');

        try {
            $result = $service->create();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $sizeMb = round($result['size'] / 1024 / 1024, 2);
        $this->info("Database backup created: storage/app/backups/{$result['path']} ({$sizeMb} MB)");

        return self::SUCCESS;
    }
}
