<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cover_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            // Sample cover rendered from CoverGeneratorService, shown on the picker
            // card — parallel to templates.thumbnail_path.
            $table->string('thumbnail_path')->nullable();
            $table->string('aspect_ratio')->default('16:9'); // 16:9 (YouTube/FB), 9:16 (Reels/TikTok/Shorts cover), 1:1
            // Text style (position/font/color/stroke/readability band) + optional
            // corner badge — see DefaultCoverTemplateConfig for the exact shape.
            $table->json('config');
            $table->string('status')->default('draft'); // draft, published, archived
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cover_templates');
    }
};
