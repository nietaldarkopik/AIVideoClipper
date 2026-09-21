<?php

namespace App\Services\Backup;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Plain-SQL pg_dump/psql backup+restore for the app's PostgreSQL database — the
 * "migrate to another server" pairing for FileBackupService (media files).
 * Shells out rather than using a PHP PostgreSQL client because pg_dump already
 * handles every schema/data edge case (sequences, extensions, column defaults)
 * correctly, and its output is restorable with nothing but psql on the target
 * machine — no PHP/Laravel required there at restore time.
 */
class DatabaseBackupService
{
    public function __construct(
        private readonly FilesystemAdapter $disk,
        private readonly string $pgDumpBin,
        private readonly string $psqlBin,
    ) {}

    /**
     * @return array{filename: string, path: string, size: int}
     */
    public function create(): array
    {
        $connection = config('database.connections.pgsql');
        if (! $connection) {
            throw new RuntimeException('No pgsql database connection configured — this backup command is PostgreSQL-only.');
        }

        $filename = 'db_'.now()->format('Y-m-d_His').'.sql';
        $this->disk->makeDirectory('database');
        $fullPath = $this->disk->path("database/{$filename}");

        $result = Process::timeout(3600)
            ->env($this->envForShellOut(['PGPASSWORD' => (string) $connection['password']]))
            ->run([
                $this->pgDumpBin,
                '--host='.$connection['host'],
                '--port='.$connection['port'],
                '--username='.$connection['username'],
                '--dbname='.$connection['database'],
                '--no-owner',
                '--no-privileges',
                '--format=plain',
                '--file='.$fullPath,
            ]);

        if (! $result->successful()) {
            $this->disk->delete("database/{$filename}");

            throw new RuntimeException('pg_dump failed: '.($result->errorOutput() ?: 'exit code '.$result->exitCode().' with no output — check that PG_DUMP_BIN is correct and the app was restarted after setting it.'));
        }

        return [
            'filename' => $filename,
            'path' => "database/{$filename}",
            'size' => $this->disk->size("database/{$filename}"),
        ];
    }

    /**
     * @return array<int, array{filename: string, size: int, created_at: string}>
     */
    public function list(): array
    {
        if (! $this->disk->exists('database')) {
            return [];
        }

        return collect($this->disk->files('database'))
            ->filter(fn ($path) => Str::endsWith($path, ['.sql', '.sql.gz']))
            ->map(fn ($path) => [
                'filename' => basename($path),
                'size' => $this->disk->size($path),
                'created_at' => date('c', $this->disk->lastModified($path)),
            ])
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    public function delete(string $filename): void
    {
        $path = $this->resolvePath($filename);
        $this->disk->delete($path);
    }

    public function absolutePath(string $filename): string
    {
        return $this->disk->path($this->resolvePath($filename));
    }

    /**
     * Restores a plain-SQL dump onto the CURRENT database connection — destructive
     * (statements in the dump run against whatever data is already there; a dump
     * taken with --no-owner/--no-privileges plus a normal CREATE TABLE will error
     * out on pre-existing tables rather than silently overwrite them, which is the
     * safer failure mode for this to have by default). Intended for a fresh
     * database on a NEW server during migration, not for restoring into a live one
     * — see the restore:database artisan command's own confirmation prompt.
     */
    public function restore(string $filename): void
    {
        $path = $this->resolvePath($filename);
        if (! $this->disk->exists($path)) {
            throw new RuntimeException("Backup file not found: {$filename}");
        }

        $connection = config('database.connections.pgsql');
        if (! $connection) {
            throw new RuntimeException('No pgsql database connection configured — this restore command is PostgreSQL-only.');
        }

        $result = Process::timeout(3600)
            ->env($this->envForShellOut(['PGPASSWORD' => (string) $connection['password']]))
            ->run([
                $this->psqlBin,
                '--host='.$connection['host'],
                '--port='.$connection['port'],
                '--username='.$connection['username'],
                '--dbname='.$connection['database'],
                '--set=ON_ERROR_STOP=on',
                '--file='.$this->disk->path($path),
            ]);

        if (! $result->successful()) {
            throw new RuntimeException('psql restore failed: '.($result->errorOutput() ?: 'exit code '.$result->exitCode()));
        }
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function envForShellOut(array $extra): array
    {
        // PHP's built-in dev server (`artisan serve`) doesn't reliably expose
        // these to proc_open()'s inherited environment the way the CLI SAPI
        // does — without them pg_dump.exe/psql.exe can fail to even load their
        // own DLLs on Windows, exiting with a blank, useless "pg_dump: error:"
        // and no further detail. A real PHP-FPM/Apache/Nginx deployment
        // inherits the OS service account's environment directly and
        // shouldn't need this, but passing them through explicitly costs
        // nothing and makes the dev-server path behave the same.
        $inherited = array_filter([
            'PATH' => getenv('PATH'),
            'SystemRoot' => getenv('SystemRoot'),
            'TEMP' => getenv('TEMP'),
            'TMP' => getenv('TMP'),
        ]);

        return $inherited + $extra;
    }

    /**
     * Rejects any filename that isn't a bare name inside the backups/database
     * directory — guards the admin download/delete endpoints against path
     * traversal via a crafted ?filename=../../.env style request.
     */
    private function resolvePath(string $filename): string
    {
        $filename = basename($filename);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new RuntimeException('Invalid backup filename.');
        }

        return "database/{$filename}";
    }
}
