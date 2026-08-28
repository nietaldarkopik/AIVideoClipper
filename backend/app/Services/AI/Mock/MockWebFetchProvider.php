<?php

namespace App\Services\AI\Mock;

use App\Services\AI\Contracts\WebFetchProvider;

/**
 * Zero-cost placeholder: deterministic filler text naming the URL, no real network
 * call — same role as every other mock provider in this app. Real fetches need
 * AI_WEB_FETCH_PROVIDER=nine_router.
 */
class MockWebFetchProvider implements WebFetchProvider
{
    public function fetch(string $url, string $format = 'markdown'): string
    {
        return "(Mock web fetch — no real content was retrieved for {$url}.)";
    }
}
