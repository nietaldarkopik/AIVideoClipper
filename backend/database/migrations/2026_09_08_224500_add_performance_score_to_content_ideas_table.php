<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The seventh component score (see TopicScorer::performance) — how much a
     * topic resembles the channel owner's best-performing published clips.
     *
     * Nullable, unlike its six siblings, because "we have no measured history for
     * this user" is a distinct state from "this scored zero": the first drops out
     * of the weighted priority entirely, the second counts against the topic.
     * Ideas generated before this column existed keep null and read the same way.
     */
    public function up(): void
    {
        Schema::table('content_ideas', function (Blueprint $table) {
            $table->unsignedTinyInteger('performance_score')->nullable()->after('cross_source_score');
        });
    }

    public function down(): void
    {
        Schema::table('content_ideas', function (Blueprint $table) {
            $table->dropColumn('performance_score');
        });
    }
};
