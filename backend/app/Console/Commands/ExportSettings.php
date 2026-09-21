<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Dumps the `settings` table (Admin > Settings page's stored provider
 * choices, e.g. transcript_model/clip_scoring_model/tts_model) as portable
 * JSON — for copying that admin-configured state to a fresh install
 * alongside .env, since these values live in the database, not in .env.
 * Contains no secrets (just provider-choice strings/ints), safe to move
 * around or commit to a private ops repo.
 */
class ExportSettings extends Command
{
    protected $signature = 'settings:export {--path= : Write to this file instead of stdout}';

    protected $description = 'Export the settings table (Admin > Settings values) as JSON';

    public function handle(): int
    {
        $rows = Setting::all(['key', 'value'])
            ->mapWithKeys(fn (Setting $row) => [$row->key => $row->value['v'] ?? null])
            ->all();

        $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($path = $this->option('path')) {
            file_put_contents($path, $json . "\n");
            $this->info("Wrote " . count($rows) . " setting(s) to {$path}");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
