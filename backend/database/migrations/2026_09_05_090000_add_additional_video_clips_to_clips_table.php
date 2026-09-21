<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            // Extra video clips appended AFTER the main clip (this row's own
            // video_id + segments), each cut from a DIFFERENT Video in the same
            // project — e.g. {video_id, start, end, transition_in}. Null/empty
            // (every clip before this feature) renders exactly as before: just
            // the main clip. Each entry is independently rendered (its own
            // smart-crop, own audio — no captions/layers of its own in this
            // pass) and the results are joined via FFmpegService::concatSegments(),
            // the same mechanism already used for the intro/outro cards, just
            // generalized to a per-boundary transition instead of one shared
            // setting. See RenderClipJob::renderAdditionalVideoClips().
            $table->json('additional_video_clips')->nullable()->after('segments');
        });
    }

    public function down(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            $table->dropColumn('additional_video_clips');
        });
    }
};
