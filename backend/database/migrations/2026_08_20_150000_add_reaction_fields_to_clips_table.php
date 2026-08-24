<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            $table->string('webcam_path')->nullable()->after('thumbnail_path');
            $table->string('reaction_layout')->nullable()->after('webcam_path');
            // pip_bottom_right, pip_bottom_left, split_top_bottom, split_side_by_side
        });
    }

    public function down(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            $table->dropColumn(['webcam_path', 'reaction_layout']);
        });
    }
};
