<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The brand/persona a research run produces ideas for ("Taofik Basuki",
     * "Exist Gaming") — NOT App\Models\ChannelWatch, which is a watched YouTube
     * upload feed. Different concept, hence content_channels.
     *
     * The spec suggests splitting niche / audience / strategy / research profile
     * into four separate tables. All four are strictly 1:1 with the channel and
     * always loaded together, so they live here as grouped columns instead —
     * matching this repo's existing convention (channel_watches.settings,
     * clips.layer_overrides). Nothing about that choice makes the system less
     * configuration-driven: every field below is user-editable from the UI.
     */
    public function up(): void
    {
        Schema::create('content_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained()->restrictOnDelete();

            // --- identity ---
            $table->string('name');
            $table->string('handle')->nullable();
            $table->text('description')->nullable();
            $table->string('language', 16)->default('id');
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->boolean('is_active')->default(true);

            // --- niche (spec: channel_niches) ---
            $table->string('niche')->nullable();
            $table->json('sub_niches')->nullable();
            $table->json('keywords')->nullable();
            $table->json('excluded_keywords')->nullable();

            // --- audience (spec: channel_audiences) ---
            $table->text('target_audience')->nullable();

            // --- strategy (spec: content_strategies) ---
            $table->json('content_style')->nullable();
            $table->json('content_types')->nullable();
            $table->json('content_formats')->nullable();
            $table->string('tone')->nullable();
            $table->json('hook_styles')->nullable();

            // --- research profile (spec: research_profiles) ---
            $table->boolean('scheduler_enabled')->default(false);
            // 'daily' | 'twice_daily' | 'every_n_hours' | 'custom'. Whatever the
            // frequency, research_times is the single source of truth the due-check
            // reads (the frequency is expanded into times on save) — so the scheduler
            // never needs a branch per frequency name.
            $table->string('research_frequency')->default('daily');
            $table->json('research_times')->nullable();          // ["06:00","12:00"] in the channel's own timezone
            $table->unsignedSmallInteger('interval_hours')->nullable(); // only for every_n_hours
            $table->unsignedSmallInteger('ideas_per_run')->default(5);
            $table->unsignedTinyInteger('min_relevance_score')->default(0);
            $table->unsignedTinyInteger('min_trend_score')->default(0);
            // Per-channel override of config('research.scoring.weights'); null = use global.
            $table->json('scoring_weights')->nullable();

            // --- scheduler bookkeeping ---
            $table->timestamp('last_research_at')->nullable();
            $table->timestamp('next_research_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index(['scheduler_enabled', 'next_research_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_channels');
    }
};
