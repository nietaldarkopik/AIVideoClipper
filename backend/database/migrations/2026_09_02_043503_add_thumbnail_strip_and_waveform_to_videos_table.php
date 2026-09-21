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
        Schema::table('videos', function (Blueprint $table) {
            // A single sprite image (N frames tiled in a row, evenly spaced across
            // the whole video, N fixed regardless of duration) and a waveform PNG —
            // see FFmpegService::generateThumbnailStrip()/generateWaveform() and
            // ImportVideoJob, which generates both once per video (not per clip)
            // right alongside the existing single-frame thumbnail_path. Nullable:
            // a video imported before this feature, or one whose source has no
            // audio track (waveform only), simply has no strip/waveform to show —
            // the timeline falls back to a plain bar, same as today.
            $table->string('thumbnail_strip_path')->nullable()->after('thumbnail_path');
            $table->string('waveform_path')->nullable()->after('thumbnail_strip_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['thumbnail_strip_path', 'waveform_path']);
        });
    }
};
