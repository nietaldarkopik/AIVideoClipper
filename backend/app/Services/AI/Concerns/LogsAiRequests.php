<?php

namespace App\Services\AI\Concerns;

use App\Services\AI\Logging\AiRequestLogger;

trait LogsAiRequests
{
    protected function aiLogger(): AiRequestLogger
    {
        return app(AiRequestLogger::class);
    }
}
