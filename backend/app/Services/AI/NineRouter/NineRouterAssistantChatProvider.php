<?php

namespace App\Services\AI\NineRouter;

use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Contracts\AssistantChatProvider;
use App\Services\AI\DTOs\AssistantReply;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Tool-calling chat through a self-hosted 9Router's OpenAI-compatible
 * /chat/completions. Router models (e.g. "Kombo") report `tools: false` in their model
 * metadata but do pass tool calls through to the model behind them — verified against
 * this instance — so capability metadata is deliberately not checked here.
 */
class NineRouterAssistantChatProvider implements AssistantChatProvider
{
    use LogsAiRequests;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly string $model = '',
        private readonly int $timeout = 300,
    ) {
    }

    public function chat(array $messages, array $tools): AssistantReply
    {
        if ($this->model === '') {
            throw new RuntimeException(
                'NINE_ROUTER_ASSISTANT_MODEL (or NINE_ROUTER_MODEL) is not set. Check GET ' . rtrim($this->baseUrl, '/') .
                '/models for the model ids your 9Router instance has credentials for.'
            );
        }

        $payload = [
            'model' => $this->model,
            'stream' => false,
            'temperature' => 0.3,
            'messages' => $messages,
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $span = $this->aiLogger()->start('assistant_chat', 'nine_router', $this->model, $this->describe($messages));

        $request = Http::timeout($this->timeout)->retry(1, 2000)->withOptions(['version' => 1.1]);
        if ($this->apiKey) {
            $request = $request->withToken($this->apiKey);
        }

        $response = $request->post(rtrim($this->baseUrl, '/') . '/chat/completions', $payload);

        if ($response->failed()) {
            $span->failure($response->body());

            throw new RuntimeException('9Router assistant chat failed (HTTP ' . $response->status() . '): ' . mb_substr($response->body(), 0, 600));
        }

        $message = $response->json('choices.0.message') ?? [];
        $content = $message['content'] ?? null;
        $content = is_string($content) && trim($content) !== '' ? $content : null;

        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $call) {
            $name = $call['function']['name'] ?? null;
            if (! $name) {
                continue;
            }

            $raw = $call['function']['arguments'] ?? '{}';
            $arguments = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?? []);

            $toolCalls[] = [
                'id' => (string) ($call['id'] ?? 'call_' . bin2hex(random_bytes(6))),
                'name' => (string) $name,
                'arguments' => is_array($arguments) ? $arguments : [],
            ];
        }

        $span->success($content ?? ('tool_calls: ' . implode(', ', array_column($toolCalls, 'name'))));

        if ($content === null && $toolCalls === []) {
            throw new RuntimeException('9Router returned an empty assistant reply.');
        }

        return new AssistantReply($content, $toolCalls, (array) $response->json('usage'), $response->json('model'));
    }

    /** Short log description — the real payload can carry base64 images and whole documents. */
    private function describe(array $messages): string
    {
        $last = end($messages) ?: [];
        $text = is_array($last['content'] ?? null) ? '[multipart]' : (string) ($last['content'] ?? '');

        return count($messages) . ' messages; last ' . ($last['role'] ?? '?') . ': ' . mb_substr($text, 0, 300);
    }
}
