<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily history behind the metrics SocialMetricsSyncer writes.
     *
     * social_posts.metrics only ever holds the LATEST numbers, so it can answer
     * "how is this post doing" but never "is it still growing" — the question that
     * actually tells you whether a format works. One row per post per day keeps
     * that history without the unbounded growth an every-sync log would have:
     * fresh posts sync hourly, so a per-sync table would be ~24 rows/post/day for
     * the same information a single end-of-day figure carries.
     */
    public function up(): void
    {
        Schema::create('social_post_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();

            // The day this reading belongs to, in app time. Paired with the unique
            // index below, each sync during a day overwrites that day's row, so the
            // stored figure is the last reading of the day rather than the first.
            $table->date('captured_on');

            // Cumulative platform totals as of this reading, not per-day gains —
            // that's what the APIs report. Day-over-day deltas are derived when the
            // chart is built (see AnalyticsController::timeseries).
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('saves')->default(0);

            $table->timestamps();

            $table->unique(['social_post_id', 'captured_on']);
            // Covers the analytics query, which walks a date window and then groups
            // by post to difference consecutive readings.
            $table->index(['captured_on', 'social_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_metric_snapshots');
    }
};
