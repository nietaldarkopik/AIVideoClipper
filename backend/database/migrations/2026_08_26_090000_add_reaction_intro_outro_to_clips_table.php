<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            $table->text('reaction_script')->nullable()->after('reaction_layout');
            // 'positive' | 'satire' — set by whichever ReactionScriptProvider generated reaction_script
            $table->string('reaction_tone')->nullable()->after('reaction_script');
            $table->boolean('intro_enabled')->default(false)->after('reaction_tone');
            $table->boolean('outro_enabled')->default(false)->after('intro_enabled');
            $table->string('intro_voice')->nullable()->after('outro_enabled');
            // Cached TTS output for reaction_script — cleared whenever reaction_script or
            // intro_voice changes (see ClipController::update()) so a stale voiceover can
            // never play against edited text; RenderClipJob regenerates it lazily when empty.
            $table->string('intro_audio_path')->nullable()->after('intro_voice');
        });
    }

    public function down(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            $table->dropColumn(['reaction_script', 'reaction_tone', 'intro_enabled', 'outro_enabled', 'intro_voice', 'intro_audio_path']);
        });
    }
};
