<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            // Per-clip overrides for the cover template's own (static) kicker
            // and subline text, so an AI-written label for THIS moment can
            // replace the template's generic one while keeping the template's
            // styling — see CoverGeneratorService. Null = use whatever the
            // template config says, exactly as before.
            $table->string('cover_kicker')->nullable()->after('cover_text');
            $table->string('cover_subline')->nullable()->after('cover_kicker');
        });
    }

    public function down(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            $table->dropColumn(['cover_kicker', 'cover_subline']);
        });
    }
};
