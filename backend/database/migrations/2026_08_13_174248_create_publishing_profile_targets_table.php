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

            $table->unique(['publishing_profile_id', 'social_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publishing_profile_targets');
    }
};
