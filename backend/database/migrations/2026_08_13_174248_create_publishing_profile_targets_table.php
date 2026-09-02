<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publishing_profile_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publishing_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Explicit short name: the auto-generated one
            // ("publishing_profile_targets_publishing_profile_id_social_account_id_unique")
            // exceeds MySQL's 64-character identifier limit (SQLite/Postgres don't
            // enforce that, so this only surfaced when adding MySQL support).
            $table->unique(['publishing_profile_id', 'social_account_id'], 'pub_profile_targets_profile_account_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publishing_profile_targets');
    }
};
