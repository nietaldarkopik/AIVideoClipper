<?php

namespace App\Providers;

use App\Services\Backup\DatabaseBackupService;
use App\Services\Backup\FileBackupService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class BackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatabaseBackupService::class, function () {
            return new DatabaseBackupService(
                disk: Storage::disk('backups'),
                pgDumpBin: config('services.backup.pg_dump_bin', 'pg_dump'),
                psqlBin: config('services.backup.psql_bin', 'psql'),
            );
        });

        $this->app->singleton(FileBackupService::class, function () {
            return new FileBackupService(
                backupsDisk: Storage::disk('backups'),
                mediaDisk: Storage::disk('media'),
                tarBin: config('services.backup.tar_bin', 'tar'),
            );
        });
    }
}
