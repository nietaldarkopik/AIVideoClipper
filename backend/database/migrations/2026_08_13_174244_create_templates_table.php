<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('aspect_ratio')->default('9:16'); // 9:16, 1:1, 16:9
            $table->unsignedInteger('resolution_width')->default(1080);
            $table->unsignedInteger('resolution_height')->default(1920);
            $table->string('status')->default('draft'); // draft, published, archived
            $table->foreignId('current_version_id')->nullable();
            $table->boolean('is_system')->default(false); // seeded built-in templates
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
