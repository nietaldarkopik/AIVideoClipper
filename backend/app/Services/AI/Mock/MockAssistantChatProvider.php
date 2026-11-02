<?php

namespace App\Services\AI\Mock;

use App\Services\AI\Contracts\AssistantChatProvider;
use App\Services\AI\DTOs\AssistantReply;

/**
 * Zero-key placeholder so the chat UI and the whole message/approval flow can be used
 * without any AI credentials. Never calls a tool — it just says it is a placeholder.
 */
class MockAssistantChatProvider implements AssistantChatProvider
{
    public function chat(array $messages, array $tools): AssistantReply
    {
        return new AssistantReply(
            'Asisten AI belum dikonfigurasi (provider: mock). Set AI_ASSISTANT_PROVIDER=nine_router di .env untuk mengaktifkannya.',
            [],
            [],
            'mock',
        );
    }
}
