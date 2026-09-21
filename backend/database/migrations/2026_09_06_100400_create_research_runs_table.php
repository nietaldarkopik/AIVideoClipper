<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger')->default('scheduled'); // scheduled | manual
            // running | success | partial | failed. PARTIAL specifically means "some
            // providers failed but the run still produced results" — never a synonym
            // for failed, and never hidden from the UI.
            $table->string('status')->default('running');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('message')->nullable();
            $table->unsignedInteger('topics_found')->default(0);
            $table->unsignedInteger('results_collected')->default(0);
            $table->unsignedInteger('ideas_generated')->default(0);
            $table->unsignedInteger('duplicates_skipped')->default(0);
            $table->json('providers_used')->nullable();
            // [{source_key, error}] — kept verbatim so a failed daily run is debuggable
            // without digging through logs.
            $table->json('providers_failed')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['content_channel_id', 'status']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_runs');
    }
};
