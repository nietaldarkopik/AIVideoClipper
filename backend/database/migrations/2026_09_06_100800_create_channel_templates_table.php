<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('platform_key')->nullable();
            // Pre-populated channel fields (niche, content_types, tone, scheduler
            // defaults...) plus a `research_source_keys` list. Purely a starting point:
            // ContentChannelController copies it into the new channel and the user
            // edits from there, so changing a template never mutates saved channels.
            $table->json('defaults')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_templates');
    }
};
