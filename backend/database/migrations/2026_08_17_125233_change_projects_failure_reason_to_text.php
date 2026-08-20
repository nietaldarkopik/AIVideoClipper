<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * projects.failure_reason was the only failure/error column in the schema still a
 * VARCHAR(255) — every equivalent column (videos, clips, processing_jobs,
 * social_posts, video_batch_items) is TEXT. Real failure messages (ffmpeg stderr,
 * full HTTP error bodies, stack trace fragments) routinely exceed 255 chars, and
 * Postgres rejects the truncated write outright rather than silently trimming it —
 * which happens *inside* AnalyzeVideoJob/ImportVideoJob's own catch block, so the
 * exception meant to record the failure throws a second one instead. That aborts
 * cleanup before ProcessingJob::markFailed() ever runs, leaving the job stuck at
 * "running" forever, and the uncaught query exception was severe enough to crash
 * the whole `queue:work` daemon rather than just that one job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->text('failure_reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('failure_reason')->nullable()->change();
        });
    }
};
