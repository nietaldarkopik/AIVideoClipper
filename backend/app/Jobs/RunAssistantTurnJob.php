<?php

namespace App\Jobs;

use App\Models\AssistantConversation;
use App\Services\Assistant\AssistantRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the assistant for one conversation: after the user sends a message, and again
 * after they approve/reject pending actions. A queued job (not the request) because a
 * turn can take minutes — several model calls, each a slow router call, plus tool work.
 */
class RunAssistantTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $conversationId)
    {
    }

    public function handle(AssistantRunner $runner): void
    {
        $conversation = AssistantConversation::find($this->conversationId);

        // Deleted while queued.
        if (! $conversation) {
            return;
        }

        $runner->run($conversation);
    }
}
