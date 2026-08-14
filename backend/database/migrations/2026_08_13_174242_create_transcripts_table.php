<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->string('language', 10)->default('en');
            $table->longText('full_text')->nullable();
            // segments: [{start, end, text, speaker}]
            $table->json('segments')->nullable();
            // words: [{word, start, end, speaker}] for word-level captions
            $table->json('words')->nullable();
            $table->json('speakers')->nullable(); // [{id, label, segments_count}]
            $table->string('provider')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcripts');
    }
};
