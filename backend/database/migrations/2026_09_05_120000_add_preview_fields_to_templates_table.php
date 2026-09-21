<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            // A real, server-rendered sample clip (see TemplatePreviewService) with
            // this template's caption/layer/crop config already burned in, so the
            // template picker can show actual footage instead of a static thumbnail.
            // Parallel to thumbnail_path/thumbnail_url above.
            $table->string('preview_path')->nullable()->after('thumbnail_path');
            $table->string('preview_status')->nullable()->after('preview_path'); // null|generating|ready|failed
            $table->timestamp('preview_generated_at')->nullable()->after('preview_status');
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn(['preview_path', 'preview_status', 'preview_generated_at']);
        });
    }
};
