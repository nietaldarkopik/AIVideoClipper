<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartoon_scenes', function (Blueprint $table) {
            // Which provider in the rollover chain actually produced each asset — lets the
            // UI flag scenes that fell back to a cheaper/free provider (e.g. camera_motion).
            $table->string('image_provider', 120)->nullable()->after('clip_path');
            $table->string('clip_provider', 120)->nullable()->after('image_provider');
        });
    }

    public function down(): void
    {
        Schema::table('cartoon_scenes', function (Blueprint $table) {
            $table->dropColumn(['image_provider', 'clip_provider']);
        });
    }
};
