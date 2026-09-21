<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('processing_jobs', function (Blueprint $table) {
            // Raw outbound request + inbound response for jobs that call a third-party
            // API (e.g. YouTube thumbnails.set) — lets a failure be debugged from the
            // admin Processing tab instead of digging through the Laravel log.
            $table->longText('request_payload')->nullable()->after('error');
            $table->longText('response_payload')->nullable()->after('request_payload');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processing_jobs', function (Blueprint $table) {
            $table->dropColumn(['request_payload', 'response_payload']);
        });
    }
};
