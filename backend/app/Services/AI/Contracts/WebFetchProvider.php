<?php

namespace App\Services\AI\Contracts;

interface WebFetchProvider
{
    /**
     * Fetch $url and return its content as $format text (markdown by default) — used
     * to give reaction-script/social-metadata generation extra context from a
     * source article. See WebContentFetcher.
     */
    public function fetch(string $url, string $format = 'markdown'): string;
}
