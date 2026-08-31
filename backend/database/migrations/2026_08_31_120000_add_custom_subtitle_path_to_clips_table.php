<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            // User-uploaded .srt/.ass, disk-relative on the 'media' disk. When set,
            // RenderClipJob uses it instead of generating captions from the
            // transcript — an .ass is burned in with its own embedded style as-is,
            // a plain .srt is styled through the same template pipeline as
            // transcript-generated captions. See RenderClipJob::handle().
            $table->string('custom_subtitle_path')->nullable()->after('subtitle_config');
        });
    }

    public function down(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            $table->dropColumn('custom_subtitle_path');
        });
    }
};
