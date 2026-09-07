<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            // User-edited caption cues, clip-relative seconds, same shape as
            // Subtitle::segments ({start, end, text, words[]}) so both the editor
            // and SubtitleService::toAss() consume one format. Null (every clip
            // before this feature) means "captions are whatever the transcript
            // produces on each render" — the previous, read-only behavior. Once
            // set, RenderClipJob burns these in verbatim instead of rebuilding
            // them from the transcript. See RenderClipJob::handle().
            $table->json('caption_cues')->nullable()->after('custom_subtitle_path');
        });
    }

    public function down(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            $table->dropColumn('caption_cues');
        });
    }
};
