<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            // Vector produced by EmbeddingProvider from this clip's title/caption/
            // hashtags/hook/reaction script — see GenerateClipEmbeddingJob and
            // ClipSearchService. embedding_model records which model produced it, so
            // a search never compares vectors from two different (incompatible)
            // models against each other.
            $table->json('embedding')->nullable()->after('reaction_tone');
            $table->string('embedding_model')->nullable()->after('embedding');
        });
    }

    public function down(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            $table->dropColumn(['embedding', 'embedding_model']);
        });
    }
};
