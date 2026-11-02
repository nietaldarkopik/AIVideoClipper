<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255)->nullable();
            // idle | running | awaiting_approval | failed
            $table->string('status', 30)->default('idle');
            $table->text('failure_reason')->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistant_conversation_id')->constrained()->cascadeOnDelete();
            // user | assistant | tool — the same roles the chat-completions API uses, so
            // history maps onto a request without translation.
            $table->string('role', 20);
            $table->longText('content')->nullable();
            // Assistant turns only: the tool calls the model asked for, verbatim.
            $table->json('tool_calls')->nullable();
            // Tool turns only: which call this answers, and which tool it was.
            $table->string('tool_call_id', 120)->nullable();
            $table->string('tool_name', 80)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['assistant_conversation_id', 'id']);
        });

        Schema::create('assistant_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Null until the message carrying the file is sent: files are uploaded first,
            // so the user can see them attached before they hit send.
            $table->foreignId('assistant_message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('original_name', 255);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('path');
            // image | document | spreadsheet | text | unsupported
            $table->string('kind', 20);
            // What the model actually reads for non-image files. Null when nothing could be extracted.
            $table->longText('extracted_text')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'assistant_message_id']);
        });

        Schema::create('assistant_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistant_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assistant_message_id')->constrained()->cascadeOnDelete();
            $table->string('tool_call_id', 120);
            $table->string('tool', 80);
            // write | outbound | destructive — copied from the tool definition at request time.
            $table->string('risk', 20);
            $table->json('arguments');
            $table->string('summary', 500);
            // pending | approved | rejected | executed | failed
            $table->string('status', 20)->default('pending');
            $table->json('result')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['assistant_conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_actions');
        Schema::dropIfExists('assistant_attachments');
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
