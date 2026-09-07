<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw, retrieved-only evidence. Every row here came back from a provider call —
     * nothing in the pipeline may write a fabricated URL, metric or headline into
     * this table (spec section 22).
     */
    public function up(): void
    {
        Schema::create('research_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_channel_id')->constrained()->cascadeOnDelete();
            $table->string('source_key');
            $table->string('external_id')->nullable();
            // Provider-supplied text: text/longText, never varchar(255) — a feed title
            // or summary regularly exceeds it.
            $table->text('title');
            $table->text('url');
            $table->text('summary')->nullable();
            $table->string('author')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('discovered_at')->nullable();
            // {score, comments, views, likes, ...} exactly as retrieved.
            $table->json('engagement')->nullable();
            $table->unsignedTinyInteger('source_score')->default(0);
            // Normalized token signature used to cluster results into topics — see
            // TopicExtractor. Stored so the UI can show which results formed a topic.
            $table->string('topic_key')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['research_run_id']);
            $table->index(['content_channel_id', 'topic_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_results');
    }
};
