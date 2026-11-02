<?php

namespace App\Services\Assistant;

use App\Models\AssistantAction;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Services\AI\Contracts\AssistantChatProvider;
use Throwable;

/**
 * Drives one assistant turn: model -> tools -> model ... until it answers in text or has to
 * stop for the user's approval.
 *
 * The approval gate lives here, in code. A tool whose risk is not `read` is never executed
 * by this loop: it is parked as a pending AssistantAction and the loop ends. Only the
 * approve endpoint can release it, and when it does the next run() executes it before
 * asking the model anything else. Nothing the model says, and nothing written inside an
 * attachment, can skip that step.
 */
class AssistantRunner
{
    public function __construct(
        private readonly AssistantChatProvider $provider,
        private readonly ToolCatalog $catalog,
        private readonly ToolExecutor $executor,
        private readonly PromptBuilder $prompts,
    ) {
    }

    public function run(AssistantConversation $conversation): void
    {
        $conversation->update(['status' => AssistantConversation::STATUS_RUNNING, 'failure_reason' => null]);
        $user = $conversation->user;

        try {
            $this->settleDecidedActions($conversation);

            $maxSteps = (int) config('assistant.max_steps', 8);

            for ($step = 1; $step <= $maxSteps; $step++) {
                if (AssistantConversation::whereKey($conversation->id)->value('cancel_requested')) {
                    $this->say($conversation, 'Dihentikan oleh pengguna.');
                    $conversation->update(['status' => AssistantConversation::STATUS_IDLE, 'cancel_requested' => false]);

                    return;
                }

                $reply = $this->provider->chat($this->prompts->build($conversation, $user), $this->catalog->functionSchemas());

                $assistant = $conversation->messages()->create([
                    'role' => AssistantMessage::ROLE_ASSISTANT,
                    'content' => $reply->content,
                    'tool_calls' => $reply->hasToolCalls() ? $reply->toolCalls : null,
                    'meta' => ['model' => $reply->model, 'usage' => $reply->usage],
                ]);
                $this->touch($conversation);

                if (! $reply->hasToolCalls()) {
                    $conversation->update(['status' => AssistantConversation::STATUS_IDLE]);

                    return;
                }

                $parked = false;

                foreach ($reply->toolCalls as $call) {
                    $parked = $this->handleCall($conversation, $assistant, $call) || $parked;
                }

                if ($parked) {
                    $conversation->update(['status' => AssistantConversation::STATUS_AWAITING_APPROVAL]);

                    return;
                }
            }

            $this->say($conversation, 'Saya berhenti karena sudah mencapai batas langkah untuk satu permintaan. Minta saya melanjutkan jika masih perlu.');
            $conversation->update(['status' => AssistantConversation::STATUS_IDLE]);
        } catch (Throwable $e) {
            report($e);
            $conversation->update(['status' => AssistantConversation::STATUS_FAILED, 'failure_reason' => mb_substr($e->getMessage(), 0, 1500)]);
        }
    }

    /**
     * @param  array{id: string, name: string, arguments: array<string, mixed>}  $call
     * @return bool true when the call was parked for approval
     */
    private function handleCall(AssistantConversation $conversation, AssistantMessage $assistant, array $call): bool
    {
        $tool = $this->catalog->find($call['name']);

        if (! $tool) {
            $this->toolResult($conversation, $call['id'], $call['name'], json_encode(['error' => true, 'message' => "Unknown tool \"{$call['name']}\". Use only the tools provided."]));

            return false;
        }

        $prepared = $this->executor->prepare($tool, $call['arguments']);

        if (isset($prepared['error'])) {
            $this->toolResult($conversation, $call['id'], $tool->name, json_encode(['error' => true, 'message' => $prepared['error']]));

            return false;
        }

        if ($tool->needsApproval()) {
            $conversation->actions()->create([
                'assistant_message_id' => $assistant->id,
                'tool_call_id' => $call['id'],
                'tool' => $tool->name,
                'risk' => $tool->risk,
                'arguments' => $prepared['args'],
                'summary' => $this->executor->summarize($tool, $prepared['args']),
                'status' => AssistantAction::STATUS_PENDING,
            ]);

            return true;
        }

        $result = $this->executor->execute($conversation->user, $tool, $prepared['args']);
        $this->toolResult($conversation, $call['id'], $tool->name, $result['content']);

        return false;
    }

    /**
     * Carry out what the user approved and record what they rejected, as tool results the
     * model will see on its next call. Each call id gets exactly one result, however many
     * times this runs.
     */
    private function settleDecidedActions(AssistantConversation $conversation): void
    {
        $actions = $conversation->actions()
            ->whereIn('status', [AssistantAction::STATUS_APPROVED, AssistantAction::STATUS_REJECTED])
            ->get();

        foreach ($actions as $action) {
            if ($conversation->messages()->where('role', AssistantMessage::ROLE_TOOL)->where('tool_call_id', $action->tool_call_id)->exists()) {
                continue;
            }

            if ($action->status === AssistantAction::STATUS_REJECTED) {
                $this->toolResult($conversation, $action->tool_call_id, $action->tool, json_encode(['rejected' => true, 'message' => 'Pengguna menolak tindakan ini. Jangan dilakukan.']));

                continue;
            }

            $tool = $this->catalog->find($action->tool);

            if (! $tool) {
                $action->update(['status' => AssistantAction::STATUS_FAILED, 'result' => ['message' => 'Tool tidak dikenal.']]);
                $this->toolResult($conversation, $action->tool_call_id, $action->tool, json_encode(['error' => true, 'message' => 'Tool tidak dikenal.']));

                continue;
            }

            try {
                $result = $this->executor->execute($conversation->user, $tool, $action->arguments);
            } catch (Throwable $e) {
                $result = ['ok' => false, 'content' => json_encode(['error' => true, 'message' => $e->getMessage()])];
            }

            $action->update([
                'status' => $result['ok'] ? AssistantAction::STATUS_EXECUTED : AssistantAction::STATUS_FAILED,
                'result' => ['ok' => $result['ok'], 'content' => mb_substr($result['content'], 0, 4000)],
            ]);

            $this->toolResult($conversation, $action->tool_call_id, $action->tool, $result['content']);
        }
    }

    private function toolResult(AssistantConversation $conversation, string $callId, string $tool, string $content): void
    {
        $conversation->messages()->create([
            'role' => AssistantMessage::ROLE_TOOL,
            'tool_call_id' => $callId,
            'tool_name' => $tool,
            'content' => $content,
        ]);
        $this->touch($conversation);
    }

    private function say(AssistantConversation $conversation, string $text): void
    {
        $conversation->messages()->create(['role' => AssistantMessage::ROLE_ASSISTANT, 'content' => $text]);
        $this->touch($conversation);
    }

    private function touch(AssistantConversation $conversation): void
    {
        $conversation->update(['last_message_at' => now()]);
    }
}
