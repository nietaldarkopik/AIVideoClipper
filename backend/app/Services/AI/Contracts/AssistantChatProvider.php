<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\DTOs\AssistantReply;

interface AssistantChatProvider
{
    /**
     * One model turn of the assistant. $messages are OpenAI-format chat messages
     * (system/user/assistant/tool, with image_url content parts for pictures) and
     * $tools are OpenAI-format function definitions. The reply is either text, one or
     * more tool calls, or both — running the tools and calling back is the caller's job.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     */
    public function chat(array $messages, array $tools): AssistantReply;
}
