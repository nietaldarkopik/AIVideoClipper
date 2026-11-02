<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartoon_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Polymorphic by hand (like projects.origin_type/origin_id): the source is a
            // content_idea or a content_brief, and neither is worth a morph map here.
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->string('title', 255);
            $table->text('topic')->nullable();
            // Short physical description of the recurring mascot, prepended to every
            // scene's image prompt so the character looks the same across scenes.
            $table->text('character_description')->nullable();
            $table->string('style', 40)->default('flat_cartoon');
            $table->string('voice', 120)->nullable();
            $table->string('motion_provider', 30)->default('camera_motion');
            $table->string('status', 30)->default('pending');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('message', 255)->nullable();
            $table->text('failure_reason')->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->string('output_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->float('duration_seconds')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('clip_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('cartoon_scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cartoon_video_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('narration');
            $table->text('visual_prompt');
            // What should MOVE in the shot ("slow zoom in on the mascot, confetti falls") —
            // sent to the image-to-video model, and mapped to a camera move by the
            // FFmpeg fallback.
            $table->text('motion_prompt')->nullable();
            $table->string('image_path')->nullable();
            $table->string('audio_path')->nullable();
            $table->string('clip_path')->nullable();
            $table->float('audio_duration')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['cartoon_video_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cartoon_scenes');
        Schema::dropIfExists('cartoon_videos');
    }
};
