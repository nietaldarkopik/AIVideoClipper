<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The app used to run on UTC (config/app.php hardcoded it, so APP_TIMEZONE was
 * never even read) while every screen renders in the browser's local zone —
 * which for this user is Asia/Jakarta, +7. The visible effect was that the
 * scheduler's "09:00-21:00 posting day" actually published at 16:00-04:00 local
 * and the whole morning looked empty on every channel.
 *
 * Switching config/app.php to Asia/Jakarta makes Laravel read every existing
 * naive `timestamp without time zone` value as local time, which would silently
 * move ~325 already-scheduled social posts 7 hours EARLIER in real terms. This
 * rewrites those stored values so each one keeps the exact instant it already
 * meant: reinterpret as UTC, then store the equivalent wall-clock time in the
 * new zone.
 *
 * Redistributing posts into sane daytime hours is deliberately NOT done here —
 * that's a "Reschedule All" the user runs when they want it, not something a
 * timezone migration should decide on their behalf.
 */
return new class extends Migration
{
    private const FROM_TIMEZONE = 'UTC';

    /**
     * Every `timestamp without time zone` column carried the old zone's wall
     * clock, so all of them shift — not just scheduled_at. Leaving created_at
     * and friends behind would make "3 hours ago" labels wrong by 7 hours for
     * every existing row.
     *
     * @return array<string, list<string>>
     */
    private function timestampColumns(): array
    {
        $rows = DB::select(
            "SELECT table_name, column_name
               FROM information_schema.columns
              WHERE table_schema = 'public'
                AND data_type LIKE 'timestamp%'
              ORDER BY table_name, column_name"
        );

        $byTable = [];
        foreach ($rows as $row) {
            // Laravel's own bookkeeping table isn't domain data and its
            // timestamps are only ever compared against each other.
            if ($row->table_name === 'migrations') {
                continue;
            }
            $byTable[$row->table_name][] = $row->column_name;
        }

        return $byTable;
    }

    private function shift(string $from, string $to): void
    {
        // Postgres-specific conversion, and the only driver this app runs on —
        // the test suite uses a fresh in-memory sqlite database where there are
        // no rows to shift anyway.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->timestampColumns() as $table => $columns) {
            $assignments = collect($columns)
                // (naive AT TIME ZONE $from) reads the stored wall clock as an
                // instant in $from; the second AT TIME ZONE renders that instant
                // as $to's wall clock. Exact for any zone/DST, unlike a hardcoded
                // "+7 hours".
                ->map(fn (string $c) => sprintf(
                    '%1$s = ("%1$s" AT TIME ZONE \'%2$s\') AT TIME ZONE \'%3$s\'',
                    $c,
                    $from,
                    $to
                ))
                ->implode(', ');

            DB::statement(sprintf('UPDATE "%s" SET %s', $table, $assignments));
        }
    }

    public function up(): void
    {
        $this->shift(self::FROM_TIMEZONE, config('app.timezone'));
    }

    public function down(): void
    {
        $this->shift(config('app.timezone'), self::FROM_TIMEZONE);
    }
};
