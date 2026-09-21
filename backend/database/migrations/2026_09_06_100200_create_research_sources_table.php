<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_sources', function (Blueprint $table) {
            $table->id();
            // Row-level registry of the providers registered in ResearchProviderManager.
            // `provider` is the manager key (the code side); `key` is this row's stable
            // identity. They are usually equal, but keeping them separate lets an admin
            // register two differently-configured rows against one provider class —
            // e.g. two RSS sources with different feed lists.
            $table->string('key')->unique();
            $table->string('provider');
            $table->string('name');
            $table->string('type')->default('general'); // trends | social | news | code | video | media | gaming | general
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            // Global defaults merged UNDER each channel's per-channel configuration
            // (channel_research_sources.configuration wins) — see ResearchSourceConfig.
            $table->json('configuration')->nullable();
            // Health, written by ResearchProviderManager::runHealthCheck() and by every
            // real run. Drives "do not repeatedly call an unavailable provider".
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamps();

            $table->index(['enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_sources');
    }
};
