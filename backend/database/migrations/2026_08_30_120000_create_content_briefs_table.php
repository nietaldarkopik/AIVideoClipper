<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // --- input ---
            $table->text('topic');
            $table->string('region_code')->nullable();
            $table->string('source_platform')->nullable();
            $table->string('source_trending_title')->nullable();
            $table->string('source_trending_url')->nullable();

            // --- pipeline state (single-entity sequential pipeline, same precedent
            // as video_batch_items rather than the polymorphic processing_jobs table) ---
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('message')->nullable();
            $table->text('failure_reason')->nullable();
            $table->boolean('cancel_requested')->default(false);

            // --- gathered research ---
            $table->json('sources')->nullable();
            $table->json('candidate_videos')->nullable();

            // --- generated script (LLM output — text/longText, never varchar(255)) ---
            $table->text('narrative_title')->nullable();
            $table->text('narrative_hook')->nullable();
            $table->json('narrative_sections')->nullable();
            $table->longText('narrative_full_script')->nullable();
            $table->text('narrative_suggested_description')->nullable();
            $table->json('narrative_suggested_hashtags')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_briefs');
    }
};
