<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            // Per-channel default cover/thumbnail template — used by
            // AutoPublishScheduler/SocialPostController to auto-generate a
            // clip's cover the first time it's scheduled to this account, if
            // the clip doesn't already have one.
            $table->foreignId('default_cover_template_id')->nullable()->after('auto_publish_enabled')
                ->constrained('cover_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_cover_template_id');
        });
    }
};
