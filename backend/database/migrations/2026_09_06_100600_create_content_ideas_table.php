<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_ideas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_channel_id')->constrained()->cascadeOnDelete();
            // nullOnDelete, not cascade: pruning old runs must never delete the ideas
            // they produced. The evidence rows in content_idea_sources keep their own
            // denormalized copies for the same reason.
            $table->foreignId('research_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('research_date');
            $table->text('topic');
            $table->text('title');
            $table->json('alternative_titles')->nullable();
            $table->text('short_description')->nullable();
            $table->text('content_angle')->nullable();
            $table->text('why_this_topic')->nullable();
            $table->text('target_audience')->nullable();
            $table->json('keywords')->nullable();
            $table->text('source_summary')->nullable();

            // All normalized 0-100. priority_score is derived from the rest via the
            // channel's (or global) configurable weights — see TopicScorer.
            $table->unsignedTinyInteger('trend_score')->default(0);
            $table->unsignedTinyInteger('relevance_score')->default(0);
            $table->unsignedTinyInteger('originality_score')->default(0);
            $table->unsignedTinyInteger('freshness_score')->default(0);
            $table->unsignedTinyInteger('engagement_score')->default(0);
            $table->unsignedTinyInteger('cross_source_score')->default(0);
            $table->unsignedTinyInteger('priority_score')->default(0);

            $table->string('suggested_content_type')->nullable();
            $table->string('suggested_format')->nullable();
            // idea | selected | scripting | draft | approved | published | rejected.
            // This phase stops at the idea; nothing auto-advances past `selected`.
            $table->string('status')->default('idea');
            $table->text('notes')->nullable();
            // Normalized title+topic token signature, checked against existing ideas of
            // the SAME channel before insert (see DuplicateDetector). Not unique at the
            // DB level: the same topic legitimately reappears across channels, and a
            // near-duplicate within a channel is rejected by similarity, not equality.
            $table->string('fingerprint')->nullable();
            $table->timestamp('selected_at')->nullable();
            $table->timestamps();

            $table->index(['content_channel_id', 'research_date']);
            $table->index(['content_channel_id', 'status']);
            $table->index(['user_id', 'priority_score']);
            $table->index(['fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_ideas');
    }
};
