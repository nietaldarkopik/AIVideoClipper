<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clip_candidate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('template_version_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title')->nullable();
            $table->text('caption')->nullable();
            $table->json('hashtags')->nullable();

            $table->decimal('start_time', 10, 2);
            $table->decimal('end_time', 10, 2);
            $table->decimal('duration', 10, 2);
            $table->string('aspect_ratio')->default('9:16');
            $table->json('crop_config')->nullable(); // {mode, keyframes:[{time,x,y,w,h}]}
            $table->json('scenes')->nullable(); // manual edit: reordered/trimmed sub-scenes

            $table->string('subtitle_language', 10)->default('en');
            $table->boolean('subtitles_enabled')->default(true);
            $table->json('subtitle_config')->nullable(); // override of template caption style

            $table->string('status')->default('queued');
            // queued, rendering, completed, failed
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('failure_reason')->nullable();

            $table->string('output_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->unsignedBigInteger('output_size_bytes')->nullable();

            $table->timestamp('rendered_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clips');
    }
};
