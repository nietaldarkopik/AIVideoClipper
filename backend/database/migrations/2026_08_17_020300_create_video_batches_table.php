<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('status')->default('pending');
            // pending, running, completed, completed_with_errors, failed, cancelled
            $table->json('settings')->nullable();
            // clip_mode, template_id, aspect_ratio, subtitle_language, subtitles_enabled, publishing_profile_id
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('completed_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->boolean('cancel_requested')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('video_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('source_url');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending');
            // pending, importing, analyzing, rendering, publishing, completed, failed, skipped, cancelled
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('message')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('clips_generated')->default(0);
            $table->unsignedInteger('posts_published')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['video_batch_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_batch_items');
        Schema::dropIfExists('video_batches');
    }
};
