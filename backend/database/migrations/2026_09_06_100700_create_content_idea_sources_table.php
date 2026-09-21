<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "View Research" for one idea. Columns are denormalized copies of the
     * research_result they came from rather than a bare foreign key, so an idea
     * still explains itself after old research_runs/results are pruned.
     */
    public function up(): void
    {
        Schema::create('content_idea_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_idea_id')->constrained()->cascadeOnDelete();
            $table->foreignId('research_result_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_key');
            $table->text('source_title');
            $table->text('source_url');
            $table->text('extracted_summary')->nullable();
            $table->json('engagement_metrics')->nullable();
            $table->unsignedTinyInteger('source_score')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('discovered_at')->nullable();
            $table->timestamps();

            $table->index(['content_idea_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_idea_sources');
    }
};
