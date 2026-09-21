<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Convenience wrapper for a full server-migration backup: database dump +
 * media archive in one command. See backup:database / backup:files for what
 * each half actually does, and this class's own handle() for the matching
 * restore:database / restore:files steps to run on the new server.
 */
class BackupRun extends Command
{
    protected $signature = 'backup:run {--gzip : Compress the media archive (see backup:files --gzip)}';

    protected $description = 'Run backup:database + backup:files together — everything needed to migrate this app to another server';

    public function handle(): int
    {
        $dbExit = $this->call('backup:database');
        if ($dbExit !== self::SUCCESS) {
            return $dbExit;
        }

        $filesExit = $this->call('backup:files', ['--gzip' => $this->option('gzip')]);
        if ($filesExit !== self::SUCCESS) {
            return $filesExit;
        }

        $this->newLine();
        $this->info('Both backups are in storage/app/backups/. To migrate to another server:');
        $this->line('  1. Copy the whole storage/app/backups/ directory to the new server (scp/rsync/external drive).');
        $this->line('  2. On the new server, with a fresh/empty database and .env pointed at it: php artisan restore:database <the .sql filename>');
        $this->line('  3. php artisan migrate --force  (brings the schema up to date if the new server is on a newer app version)');
        $this->line('  4. php artisan restore:files <the .tar filename>');

        return self::SUCCESS;
    }
}
