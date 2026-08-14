<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            // tiktok, youtube, instagram, facebook, twitter, linkedin
            $table->string('account_name');
            $table->string('username')->nullable();
            $table->string('avatar_url')->nullable();
            $table->string('external_account_id')->nullable();

            $table->string('status')->default('connected');
            // connected, expired, revoked, error
            $table->text('access_token')->nullable(); // encrypted cast
            $table->text('refresh_token')->nullable(); // encrypted cast
            $table->timestamp('token_expires_at')->nullable();
            $table->json('permissions')->nullable();

            $table->boolean('auto_publish_enabled')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'platform', 'external_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
