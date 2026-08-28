<?php

namespace App\Services\AI;

use App\Services\AI\Contracts\WebFetchProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin, resilient wrapper around WebFetchProvider for optional reference-URL
 * context (reaction script / social metadata generation) — a bad/unreachable URL
 * must never fail the request it's enriching, same resilience pattern as the TTS
 * failure handling in RenderClipJob::composeIntroOutro().
 */
class WebContentFetcher
{
    public function __construct(
        private readonly WebFetchProvider $provider,
    ) {
    }

    public function fetch(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        try {
            $content = $this->provider->fetch($url);

            return blank($content) ? null : $content;
        } catch (Throwable $e) {
            Log::warning('Reference URL fetch failed, generating without it', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
