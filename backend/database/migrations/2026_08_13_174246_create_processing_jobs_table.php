<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('clip_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type');
            // import_video, process_video, extract_audio, transcribe, analyze_transcript,
            // detect_scenes, generate_candidates, render_clip, generate_subtitles, publish
            $table->string('status')->default('queued');
            // queued, running, completed, failed, cancelled
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('message')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_jobs');
    }
};
