<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_research_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('research_source_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            // weight scales this source's contribution to a topic's trend/cross-source
            // score; priority only orders execution (lower runs first) so a channel's
            // most-trusted sources still produce results if a later one times out.
            $table->decimal('weight', 4, 2)->default(1.00);
            $table->unsignedSmallInteger('priority')->default(100);
            // Source-specific overrides: subreddits, feed_urls, regions, categories,
            // min_score, language, keywords... Shape is owned by each provider's
            // configSchema(), never by this table.
            $table->json('configuration')->nullable();
            $table->timestamps();

            $table->unique(['content_channel_id', 'research_source_id'], 'channel_research_source_unique');
            $table->index(['content_channel_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_research_sources');
    }
};
