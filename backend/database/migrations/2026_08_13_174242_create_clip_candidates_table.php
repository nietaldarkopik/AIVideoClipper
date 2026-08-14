<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clip_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->decimal('start_time', 10, 2);
            $table->decimal('end_time', 10, 2);
            $table->decimal('duration', 10, 2);

            $table->unsignedTinyInteger('overall_score');
            $table->unsignedTinyInteger('engagement_score');
            $table->unsignedTinyInteger('hook_score');
            $table->unsignedTinyInteger('story_score');
            $table->unsignedTinyInteger('emotional_score');
            $table->unsignedTinyInteger('information_score');
            $table->unsignedTinyInteger('viral_potential');

            $table->string('hook_text')->nullable();
            $table->string('moment_type')->nullable();
            // hook, emotional, funny, controversial, educational, story_peak, conclusion, question
            $table->json('reasons')->nullable(); // list of strings, e.g. ["Strong hook in first 3 seconds", ...]
            $table->text('explanation')->nullable();

            $table->string('suggested_title')->nullable();
            $table->text('suggested_caption')->nullable();
            $table->json('suggested_hashtags')->nullable();

            $table->string('status')->default('pending'); // pending, generated, dismissed
            $table->timestamps();

            $table->index(['project_id', 'overall_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clip_candidates');
    }
};
