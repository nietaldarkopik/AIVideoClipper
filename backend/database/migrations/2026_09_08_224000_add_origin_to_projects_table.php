<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records where a project came from — a content brief, a trending item, a
     * research idea — so published performance can eventually be traced back to
     * the thing that suggested it.
     *
     * Until now nothing connected the two halves of the app: the research engine
     * produces ideas, projects produce clips, and selecting an idea only flipped
     * its status. That gap is why the research scorer can't yet learn from real
     * outcomes — there was no way to say which post came from which idea.
     *
     * Deliberately a loose (type, id) pair rather than Laravel's polymorphic
     * morphTo: 'trending' items have no local row to point at (they're fetched
     * live from each platform), so origin_id is nullable and the type alone is
     * still a useful signal. A morphTo relation would imply every origin resolves
     * to a model, and this one doesn't.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('origin_type')->nullable()->after('description');
            $table->unsignedBigInteger('origin_id')->nullable()->after('origin_type');

            $table->index(['origin_type', 'origin_id']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['origin_type', 'origin_id']);
            $table->dropColumn(['origin_type', 'origin_id']);
        });
    }
};
