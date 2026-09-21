<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            // nullOnDelete, not cascade: a converted idea keeps existing even if the
            // sourcing message row is later pruned, same reasoning as content_ideas'
            // research_run_id.
            $table->foreignId('content_idea_id')->nullable()->constrained()->nullOnDelete();

            $table->string('external_conversation_id');
            $table->string('external_message_id');
            $table->string('sender_name')->nullable();
            $table->string('sender_psid')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('sent_at')->nullable();

            // new | ignored | converted
            $table->string('status')->default('new');
            $table->timestamps();

            // Facebook message ids are globally unique already, but scoping the
            // uniqueness to the Page avoids relying on that guarantee.
            $table->unique(['social_account_id', 'external_message_id']);
            $table->index(['social_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_messages');
    }
};
