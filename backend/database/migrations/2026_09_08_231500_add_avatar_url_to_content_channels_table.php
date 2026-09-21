<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The channel's logo, so a content channel is recognisable at a glance in the
     * research UI the way connected social accounts already are.
     *
     * Stores the platform's own remote URL rather than a downloaded copy, matching
     * social_accounts.avatar_url — these are stable CDN URLs (yt3.ggpht.com for
     * YouTube), and mirroring them into local storage would mean a fetch, a
     * storage path, and a staleness problem for an image that is only ever
     * decorative.
     */
    public function up(): void
    {
        Schema::table('content_channels', function (Blueprint $table) {
            $table->string('avatar_url', 1000)->nullable()->after('handle');
        });
    }

    public function down(): void
    {
        Schema::table('content_channels', function (Blueprint $table) {
            $table->dropColumn('avatar_url');
        });
    }
};
