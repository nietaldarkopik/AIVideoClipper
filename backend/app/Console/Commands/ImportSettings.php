<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Applies a JSON file produced by `settings:export` — the other half of
 * moving the Admin > Settings page's stored provider choices to a fresh
 * install. Upserts by key via Setting::set(), so it's safe to re-run.
 */
class ImportSettings extends Command
{
    protected $signature = 'settings:import {path : JSON file produced by settings:export}';

    protected $description = 'Import a settings.json file (from settings:export) into the settings table';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode(file_get_contents($path), true);

        if (! is_array($rows)) {
            $this->error("{$path} doesn't contain a valid JSON object.");

            return self::FAILURE;
        }

        foreach ($rows as $key => $value) {
            Setting::set($key, $value);
            $this->line("{$key} = " . json_encode($value));
        }

        $this->info('Imported ' . count($rows) . ' setting(s).');

        return self::SUCCESS;
    }
}
