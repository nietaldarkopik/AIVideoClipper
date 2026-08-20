<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same bug class as the projects.failure_reason migration: hook_text and
 * suggested_title were VARCHAR(255), but an AI analysis provider's "quote the
 * exact opening line from the transcript" instruction can easily produce a
 * spoken-language sentence well over 255 chars — Postgres rejects the insert
 * outright rather than truncating it. clips.title is included too since
 * ClipGenerationService copies suggested_title into it verbatim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clip_candidates', function (Blueprint $table) {
            $table->text('hook_text')->nullable()->change();
            $table->text('suggested_title')->nullable()->change();
        });

        Schema::table('clips', function (Blueprint $table) {
            $table->text('title')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('clip_candidates', function (Blueprint $table) {
            $table->string('hook_text')->nullable()->change();
            $table->string('suggested_title')->nullable()->change();
        });

        Schema::table('clips', function (Blueprint $table) {
            $table->string('title')->nullable()->change();
        });
    }
};
