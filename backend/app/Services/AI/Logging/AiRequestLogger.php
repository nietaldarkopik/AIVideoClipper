<?php

namespace App\Services\AI\Logging;

/**
 * Entry point for AI provider request/response logging — see App\Services\AI\Concerns\LogsAiRequests,
 * used by every real (non-mock) provider class. Kept as a plain container-resolved
 * service (not constructor-injected into providers, which are all built via bare
 * `new X(...)` in AIServiceProvider) so logging can be added/removed per provider
 * with a one-line trait use, no binding changes.
 */
class AiRequestLogger
{
    public function start(string $capability, string $provider, ?string $model, ?string $prompt): AiRequestLogSpan
    {
        return new AiRequestLogSpan($capability, $provider, $model, $prompt);
    }
}
