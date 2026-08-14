<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source_type'); // upload, youtube, tiktok, instagram, facebook, twitter, vimeo, url
            $table->string('source_url')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('disk_path')->nullable();
            $table->string('audio_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('title')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('status')->default('pending');
            // pending, downloading, uploaded, processing, ready, failed
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
