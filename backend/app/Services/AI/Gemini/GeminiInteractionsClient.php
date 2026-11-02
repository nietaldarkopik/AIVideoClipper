<?php

namespace App\Services\AI\Gemini;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for Gemini's Interactions API (POST /v1beta/interactions), which is
 * where the current image (gemini-nano-banana-*) and video (gemini-omni-*) models
 * live — the older generateContent/predictLongRunning Veo preview models were shut
 * down 2026-10-22. Image and video responses share one shape, so both providers
 * use this instead of each re-parsing it.
 *
 * Note: these models have a free-tier quota of 0 — a key without billing enabled
 * gets HTTP 429 "limit: 0 ... on Free Tier" on every call.
 */
class GeminiInteractionsClient
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeout = 300,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not set.');
        }

        $response = Http::timeout($this->timeout)
            // Retries only connection-level failures: throw:false hands HTTP errors
            // (notably 429 quota) straight back instead of hammering a closed quota.
            ->retry(2, 3000, throw: false)
            ->withHeaders(['x-goog-api-key' => $this->apiKey])
            ->post(self::BASE . '/interactions', $payload);

        if ($response->failed()) {
            throw new RuntimeException("Gemini interactions call failed (HTTP {$response->status()}): " . $response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Find the first generated media block of $type ('image' | 'video') anywhere in
     * the response and return its bytes. Searches recursively rather than hardcoding
     * steps[].content[] because that nesting differs between SDK-documented shapes;
     * a block carries either inline base64 `data` or a hosted `uri` (delivery: "uri").
     */
    public function extractMedia(array $response, string $type): string
    {
        $block = $this->findBlock($response, $type);

        if ($block === null) {
            throw new RuntimeException("Gemini response contained no {$type} output: " . mb_substr(json_encode($this->redact($response)), 0, 600));
        }

        if (! empty($block['data'])) {
            $bytes = base64_decode((string) $block['data'], true);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException("Gemini returned an undecodable {$type} payload.");
            }

            return $bytes;
        }

        return $this->download((string) $block['uri']);
    }

    private function download(string $uri): string
    {
        // Hosted files are served from the Files API and need the key on the download.
        $response = Http::timeout($this->timeout)
            ->withHeaders(['x-goog-api-key' => $this->apiKey])
            ->get(str_contains($uri, '?') ? $uri : $uri . '?alt=media');

        if ($response->failed()) {
            throw new RuntimeException("Gemini media download failed (HTTP {$response->status()}).");
        }

        return $response->body();
    }

    private function findBlock(mixed $node, string $type): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        if (($node['type'] ?? null) === $type && (! empty($node['data']) || ! empty($node['uri']))) {
            return $node;
        }

        foreach ($node as $child) {
            if (($found = $this->findBlock($child, $type)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** Strip base64 blobs so an error message stays readable and loggable. */
    private function redact(mixed $node): mixed
    {
        if (is_array($node)) {
            return array_map(fn ($v) => $this->redact($v), $node);
        }

        return is_string($node) && strlen($node) > 200 ? substr($node, 0, 40) . '…(' . strlen($node) . ' chars)' : $node;
    }
}
