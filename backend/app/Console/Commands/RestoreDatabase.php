<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseBackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Restores a plain-SQL dump (from backup:database) onto the CURRENT database
 * connection. Meant to be run once, against a fresh/empty database on a newly
 * migrated server — running it against a database that already has data will
 * fail on the first CREATE TABLE that already exists rather than silently
 * overwrite anything, since the dump has no DROP statements (see
 * DatabaseBackupService::create()'s --no-owner/--no-privileges plain dump).
 */
class RestoreDatabase extends Command
{
    protected $signature = 'restore:database {filename : File name inside storage/app/backups/database, e.g. db_2026-09-07_120000.sql} {--force : Skip the confirmation prompt}';

    protected $description = 'Restore a pg_dump SQL backup onto the current database (destructive — run only on a fresh/empty database)';

    public function handle(DatabaseBackupService $service): int
    {
        $filename = $this->argument('filename');
        $database = config('database.connections.pgsql.database');

        if (! $this->option('force') && ! $this->confirm(
            "This runs every statement in {$filename} against the '{$database}' database on this server's DB_HOST. Continue?"
        )) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $this->info("Restoring {$filename} into '{$database}'...");

        try {
            $service->restore($filename);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Database restore complete.');

        return self::SUCCESS;
    }
}
