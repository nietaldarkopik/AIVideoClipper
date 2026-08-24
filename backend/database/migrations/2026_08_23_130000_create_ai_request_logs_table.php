<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('capability');
            // content_analysis, transcription, social_metadata
            $table->string('provider');
            // ollama, openai, claude, gemini, nine_router, whisper_engine
            $table->string('model')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('video_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('clip_id')->nullable()->constrained()->nullOnDelete();
            $table->text('prompt')->nullable();
            $table->text('response')->nullable();
            $table->string('status');
            // success, failed
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['capability', 'provider', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_request_logs');
    }
};
