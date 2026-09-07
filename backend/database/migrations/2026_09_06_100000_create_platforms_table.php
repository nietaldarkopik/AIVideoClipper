<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platforms', function (Blueprint $table) {
            $table->id();
            // Stable machine key ('youtube', 'tiktok'). Deliberately NOT constrained to
            // an enum/check: adding a platform must be a row insert from the UI, never
            // a migration (see the research engine spec, "no hardcoded platforms").
            $table->string('key')->unique();
            $table->string('name');
            // 'video' | 'short_video' | 'image' | 'text' | 'audio' | 'web' — drives
            // nothing in business logic by itself; it only seeds a new channel's
            // default content formats via default_strategy below.
            $table->string('type')->default('video');
            // Platform-aware content strategy defaults (formats, pacing, hook styles).
            // Copied into a channel at creation time, then owned by the channel — so
            // editing a platform later never silently rewrites existing channels.
            $table->json('default_strategy')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['enabled', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platforms');
    }
};
