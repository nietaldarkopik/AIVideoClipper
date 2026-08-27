<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            // Multi-segment selection: a list of {start, end} ABSOLUTE source-video
            // time pairs (like start_time/end_time, but N of them) to cut and
            // concatenate into one output — a "jump cut" multi-trim. Null/empty means
            // "single contiguous segment", i.e. exactly today's start_time/end_time
            // behavior (see RenderClipJob, which synthesizes a one-item segment list
            // from start_time/end_time when this column is empty).
            $table->json('segments')->nullable()->after('layer_overrides');
        });
    }

    public function down(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            $table->dropColumn('segments');
        });
    }
};
