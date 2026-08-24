<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform')->default('youtube');
            $table->string('channel_id');
            $table->string('channel_title')->nullable();
            $table->string('channel_url');
            $table->string('thumbnail_url')->nullable();
            // Resolved once at creation (YouTubeChannelMonitor::resolveChannel) so
            // every poll after that is a single 1-quota-unit playlistItems.list call
            // instead of re-resolving the channel every 15 minutes.
            $table->string('uploads_playlist_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            // clip_mode, template_id, aspect_ratio, subtitle_language, subtitles_enabled, publishing_profile_id
            $table->string('last_video_id')->nullable();
            $table->timestamp('last_video_published_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'platform', 'channel_id']);
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_watches');
    }
};
