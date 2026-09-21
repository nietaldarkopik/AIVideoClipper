<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            // Optional source article/page the user wants reaction-script / social-
            // metadata generation to use as extra context — see WebContentFetcher,
            // ClipController::generateReactionScript()/generateSocialMetadata().
            $table->string('reference_url')->nullable()->after('embedding_model');
        });
    }

    public function down(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            $table->dropColumn('reference_url');
        });
    }
};
