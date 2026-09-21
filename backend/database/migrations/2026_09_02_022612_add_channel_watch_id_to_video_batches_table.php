<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('video_batches', function (Blueprint $table) {
            // Null for a manually-submitted batch (VideoBatchController::store) —
            // only ChannelWatchPoller-created batches set this. Lets a later fix to
            // a channel's publishing_profile_id find every project that came from
            // it, to resync already-scheduled posts onto the corrected target
            // account(s) — see AutoPublishScheduler::resyncChannelWatchProfile().
            // nullOnDelete rather than cascading: deleting the watch shouldn't take
            // its already-rendered batches/clips down with it.
            $table->foreignId('channel_watch_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_watch_id');
        });
    }
};
