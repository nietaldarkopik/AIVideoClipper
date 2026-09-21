<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The previous migration (add_channel_watch_id_to_video_batches_table) only
 * gets a value going FORWARD — ChannelWatchPoller sets it on every new batch
 * it creates, but every batch created before that migration ran is NULL, so
 * AutoPublishScheduler::resyncChannelWatchProfile() can't find any of a
 * channel's PRE-EXISTING clips when the user fixes its publishing target
 * (reported: fixing a channel's target didn't move its already-scheduled
 * clips at all — because there was nothing linking them back to it yet).
 *
 * Backfills the link for existing rows by matching a batch's name — always
 * "Auto: {channel_title}", set by ChannelWatchPoller::pollOne() — back to the
 * channel watch with that title, scoped per-user (two users could watch
 * differently-owned channels that happen to share a title). Written in plain
 * PHP rather than a single UPDATE...JOIN so it works the same regardless of
 * DB driver (this app runs on pgsql, but nothing here should assume that).
 */
return new class extends Migration
{
    public function up(): void
    {
        $watchesByUser = DB::table('channel_watches')
            ->select('id', 'user_id', 'channel_title')
            ->get()
            ->groupBy('user_id');

        DB::table('video_batches')
            ->whereNull('channel_watch_id')
            ->where('name', 'like', 'Auto: %')
            ->orderBy('id')
            ->chunkById(200, function ($batches) use ($watchesByUser) {
                foreach ($batches as $batch) {
                    $title = substr($batch->name, strlen('Auto: '));
                    $match = ($watchesByUser[$batch->user_id] ?? collect())->firstWhere('channel_title', $title);
                    if ($match) {
                        DB::table('video_batches')->where('id', $batch->id)->update(['channel_watch_id' => $match->id]);
                    }
                }
            });
    }

    /**
     * Not meaningfully reversible: every batch had channel_watch_id = null
     * before this ran, but blindly nulling everything back out on rollback
     * would also erase links ChannelWatchPoller has since set on brand new
     * batches created after this migration (which were never this
     * migration's doing) — so this intentionally leaves data as-is rather
     * than guess which rows it's safe to touch.
     */
    public function down(): void
    {
    }
};
