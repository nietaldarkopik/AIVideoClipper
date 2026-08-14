<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('label')->nullable(); // e.g. "v3"
            $table->boolean('is_published')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Full template config snapshot. Structure:
            // {
            //   aspect_ratio, resolution: {width, height},
            //   layers: [{ id, type: video|subtitle|image|logo|text|shape|audio|progress_bar|overlay,
            //              x, y, width, height, rotation, opacity, z_index, timing: {start,end}, props: {...} }],
            //   caption: { font, font_size, color, background, stroke, position, animation, highlight_active_word },
            //   branding: { logo_path, watermark_path, watermark_opacity },
            //   intro: {...}|null, outro: {...}|null, transition: {...}|null,
            //   music: {...}|null, sound_effects: [...],
            //   crop_rules: { mode: speaker_centered|manual|smart, ... },
            //   overlay: [...], progress_bar: {...}|null, cta: {...}|null,
            //   variables: ["video","subtitle","speaker","title","logo","username","cta","progress"]
            // }
            $table->json('config');

            $table->timestamps();

            $table->unique(['template_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_versions');
    }
};
