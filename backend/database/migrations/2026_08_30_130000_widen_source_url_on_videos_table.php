<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A candidate video URL from Riset Konten's web-search discovery (or any other
 * long-URL source — some real TikTok/Instagram share links with tracking params
 * run past 255 chars too) can exceed varchar(255) and crash the insert. Same
 * lesson already learned for LLM text columns in
 * 2026_08_17_140229_widen_llm_generated_text_columns.php — applies here to
 * arbitrary external URLs too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->text('source_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->string('source_url')->nullable()->change();
        });
    }
};
