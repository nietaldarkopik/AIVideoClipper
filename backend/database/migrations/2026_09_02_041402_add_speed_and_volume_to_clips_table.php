<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            // 0.50-2.00 — see FFmpegService::renderClip()'s final setpts=PTS/speed
            // (video) + atempo=speed (audio) stage, applied AFTER crop/caption/
            // layers/silence-removal so everything else stays computed in real,
            // untouched time and only the fully composited result gets time-scaled.
            $table->decimal('speed', 3, 2)->default(1.00)->after('duration');
            // 0.00-2.00 — plain `volume=` filter on the source audio pad.
            $table->decimal('volume', 3, 2)->default(1.00)->after('speed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            $table->dropColumn(['speed', 'volume']);
        });
    }
};
