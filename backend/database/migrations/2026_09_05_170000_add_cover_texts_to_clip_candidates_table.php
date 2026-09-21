<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clip_candidates', function (Blueprint $table) {
            // Short, thumbnail-sized headline/kicker variants produced by the
            // same ContentAnalysisProvider pass that finds the moment itself —
            // suggested_title is written for a post caption and is regularly far
            // too long to burn onto a cover. Seeded onto the Clip's own cover
            // fields at creation time; see CoverGeneratorService.
            $table->json('cover_titles')->nullable()->after('suggested_hashtags');
            $table->json('cover_subtitles')->nullable()->after('cover_titles');
        });
    }

    public function down(): void
    {
        Schema::table('clip_candidates', function (Blueprint $table) {
            $table->dropColumn(['cover_titles', 'cover_subtitles']);
        });
    }
};
