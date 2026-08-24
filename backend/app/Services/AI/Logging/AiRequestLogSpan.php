<?php

namespace App\Services\AI\Logging;

use App\Models\AiRequestLog;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * A single AI request's start/stop handle, returned by AiRequestLogger::start().
 * Exactly one of success()/failure() should be called once the request settles —
 * doing the actual DB write here (rather than in start()) is what lets duration_ms
 * be measured across the real request instead of just the setup.
 */
class AiRequestLogSpan
{
    private const MAX_TEXT_LENGTH = 50000;

    private const MAX_ERROR_LENGTH = 5000;

    private float $startedAt;

    public function __construct(
        private readonly string $capability,
        private readonly string $provider,
        private readonly ?string $model,
        private readonly ?string $prompt,
    ) {
        $this->startedAt = microtime(true);
    }

    public function success(?string $response): void
    {
        $this->write(AiRequestLog::STATUS_SUCCESS, $response, null);
    }

    public function failure(string $errorMessage, ?string $partialResponse = null): void
    {
        $this->write(AiRequestLog::STATUS_FAILED, $partialResponse, $errorMessage);
    }

    private function write(string $status, ?string $response, ?string $errorMessage): void
    {
        AiRequestLog::create([
            'capability' => $this->capability,
            'provider' => $this->provider,
            'model' => $this->model,
            'project_id' => Context::get('project_id'),
            'video_id' => Context::get('video_id'),
            'clip_id' => Context::get('clip_id'),
            'prompt' => $this->truncate($this->prompt, self::MAX_TEXT_LENGTH),
            'response' => $this->truncate($response, self::MAX_TEXT_LENGTH),
            'status' => $status,
            'error_message' => $this->truncate($errorMessage, self::MAX_ERROR_LENGTH),
            'duration_ms' => (int) round((microtime(true) - $this->startedAt) * 1000),
        ]);
    }

    private function truncate(?string $text, int $limit): ?string
    {
        return $text === null ? null : Str::limit($text, $limit, '... [truncated]');
    }
}
