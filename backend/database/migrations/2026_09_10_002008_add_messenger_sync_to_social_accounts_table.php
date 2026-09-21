<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            // Opt-in per Page: only accounts explicitly enabled here get polled for
            // Messenger conversations. Content-research use case, not every connected
            // Page is a message-intake page.
            $table->boolean('messenger_sync_enabled')->default(false)->after('auto_publish_enabled');
            $table->timestamp('messenger_last_synced_at')->nullable()->after('messenger_sync_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn(['messenger_sync_enabled', 'messenger_last_synced_at']);
        });
    }
};
