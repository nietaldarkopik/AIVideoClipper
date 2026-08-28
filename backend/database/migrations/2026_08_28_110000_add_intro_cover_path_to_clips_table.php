<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            // Cached cover image for the reaction intro (ImageGenerationProvider
            // output, or just the grabbed video frame under the mock provider) — same
            // caching role as intro_audio_path: only (re)generated when empty/missing,
            // cleared whenever reaction_script changes (see ClipController::update()),
            // so a real image-gen provider isn't called on every re-render.
            $table->string('intro_cover_path')->nullable()->after('intro_audio_path');
        });
    }

    public function down(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            $table->dropColumn('intro_cover_path');
        });
    }
};
