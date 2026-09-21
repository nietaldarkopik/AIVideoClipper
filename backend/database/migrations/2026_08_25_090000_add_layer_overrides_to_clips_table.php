<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            // Per-clip overrides for a template's config['layers'] (see
            // LayerOverrideMerger): keyed by layer id, plus optional "_new" (clip-only
            // extra layers) and "_removed" (hide a template layer for this clip only).
            // Null/empty means "use the template's layers unmodified" — mirrors the
            // existing subtitle_config override pattern for captions.
            $table->json('layer_overrides')->nullable()->after('subtitle_config');
        });
    }

    public function down(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            $table->dropColumn('layer_overrides');
        });
    }
};
