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
        Schema::table('social_posts', function (Blueprint $table) {
            // null: no cover was available to upload (not applicable).
            // pending: cover exists but upload has not been confirmed yet.
            // uploaded: thumbnails.set confirmed successful (initial or reapply).
            // failed: every attempt (initial + reapply retries) came back failed.
            $table->string('thumbnail_status')->nullable()->after('external_post_id');
            $table->timestamp('thumbnail_uploaded_at')->nullable()->after('thumbnail_status');
            $table->text('thumbnail_error')->nullable()->after('thumbnail_uploaded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn(['thumbnail_status', 'thumbnail_uploaded_at', 'thumbnail_error']);
        });
    }
};
