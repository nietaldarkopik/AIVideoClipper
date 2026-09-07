<?php

namespace App\Services\Research\Providers;

use App\Services\Research\Contracts\ResearchProvider;
use App\Services\Research\DTOs\ProviderHealth;
use App\Services\Research\DTOs\ResearchItem;
use App\Services\Research\DTOs\ResearchQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * Shared HTTP behaviour for every real research provider: timeout, exponential
 * backoff, a response cache keyed by the full request, and RSS/Atom parsing.
 *
 * Retries go through Http::retry rather than a hand-rolled loop so a
 * non-retryable 4xx (a bad API key, a malformed query) fails immediately instead
 * of burning the full backoff schedule against an endpoint that will never
 * succeed.
 */
abstract class AbstractHttpResearchProvider implements ResearchProvider
{
    public function requiresCredentials(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function trending(ResearchQuery $query): array
    {
        // Most sources have no separate trending concept; keyword search is the
        // honest answer for them. Providers that do have one override this.
        return [];
    }

    public function configSchema(): array
    {
        return [];
    }

    public function healthCheck(): ProviderHealth
    {
        if ($this->requiresCredentials() && ! $this->isConfigured()) {
            return new ProviderHealth($this->key(), false, 'Kredensial belum dikonfigurasi.', configured: false);
        }

        $startedAt = microtime(true);

        try {
            // A real, minimal retrieval — not a bare ping. A provider whose endpoint is
            // up but whose credentials are rejected has to read as unhealthy here, and
            // only an actual call can tell us that.
            $items = $this->search(new ResearchQuery(
                keywords: ['ai'],
                language: 'en',
                region: 'US',
                limit: 3,
                config: $this->healthCheckConfig(),
            ));

            $latency = (int) round((microtime(true) - $startedAt) * 1000);

            return new ProviderHealth(
                $this->key(),
                true,
                sprintf('OK - %d hasil dalam %d ms.', count($items), $latency),
                latencyMs: $latency,
            );
        } catch (Throwable $e) {
            return new ProviderHealth(
                $this->key(),
                false,
                $e->getMessage(),
                latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
            );
        }
    }

    /**
     * Config a health check needs in order to make a meaningful call — e.g. the RSS
     * provider has nothing to fetch without a feed URL.
     *
     * @return array<string, mixed>
     */
    protected function healthCheckConfig(): array
    {
        return [];
    }

    protected function http(): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => config('research.http.user_agent')])
            ->timeout((int) config('research.http.timeout', 20))
            ->retry(
                (int) config('research.http.retries', 2) + 1,
                (int) config('research.http.retry_base_ms', 400),
                // Retry transport errors plus 429/5xx only. Retrying a 401/403/404 just
                // multiplies a permanent failure and delays the rest of the run.
                function (Throwable $e) {
                    $status = property_exists($e, 'response') ? $e->response?->status() : null;

                    return $status === null || $status === 429 || $status >= 500;
                },
                throw: true,
            )
            ->throw();
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $headers
     * @return array<mixed>
     */
    protected function getJson(string $url, array $params = [], array $headers = []): array
    {
        $cacheKey = 'json:'.$url.':'.md5((string) json_encode([$params, $headers]));

        return $this->cached($cacheKey, function () use ($url, $params, $headers) {
            $decoded = $this->http()->withHeaders($headers)->get($url, $params)->json();

            return is_array($decoded) ? $decoded : [];
        });
    }

    /**
     * Fetch and parse an RSS/Atom feed into a uniform item shape.
     *
     * @return array<int, array{title: string, link: string, description: ?string, published_at: ?CarbonImmutable}>
     */
    protected function getFeed(string $url): array
    {
        return $this->cached('feed:'.$url, fn () => $this->parseFeed($this->http()->get($url)->body()));
    }

    /**
     * @return array<int, array{title: string, link: string, description: ?string, published_at: ?CarbonImmutable}>
     */
    protected function parseFeed(string $xml): array
    {
        if (trim($xml) === '') {
            return [];
        }

        // libxml_use_internal_errors: real-world feeds regularly carry undeclared
        // entities or stray control characters. Without this, one malformed feed emits
        // PHP warnings and returns false, taking the whole provider call down with it.
        $previous = libxml_use_internal_errors(true);

        try {
            $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($doc === false) {
            return [];
        }

        $items = [];

        foreach ($doc->channel->item ?? [] as $node) {   // RSS 2.0
            $items[] = $this->feedNodeToArray($node, isAtom: false);
        }

        foreach ($doc->entry ?? [] as $node) {           // Atom
            $items[] = $this->feedNodeToArray($node, isAtom: true);
        }

        return array_values(array_filter($items, fn ($item) => $item['title'] !== '' && $item['link'] !== ''));
    }

    /**
     * @return array{title: string, link: string, description: ?string, published_at: ?CarbonImmutable}
     */
    private function feedNodeToArray(SimpleXMLElement $node, bool $isAtom): array
    {
        $link = '';

        if ($isAtom) {
            // Atom keeps the URL in an attribute and may carry several <link> elements
            // (alternate/self/enclosure) — prefer the alternate, fall back to the first.
            foreach ($node->link ?? [] as $linkNode) {
                $rel = (string) ($linkNode['rel'] ?? 'alternate');
                if ($rel === 'alternate' || $link === '') {
                    $link = (string) ($linkNode['href'] ?? '');
                }
                if ($rel === 'alternate') {
                    break;
                }
            }
        } else {
            $link = trim((string) $node->link);
        }

        $description = trim(strip_tags((string) ($node->description ?? $node->summary ?? $node->content ?? '')));

        return [
            'title' => $this->decode((string) $node->title),
            'link' => $link,
            'description' => $description !== '' ? mb_substr($this->decode($description), 0, 1000) : null,
            'published_at' => $this->parseDate((string) ($node->pubDate ?? $node->published ?? $node->updated ?? '')),
        ];
    }

    protected function decode(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    protected function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            // An unparseable date must not take down the fetch — publishing time is
            // optional everywhere downstream (it only softens the freshness score).
            return null;
        }
    }

    /**
     * @template TCached
     *
     * @param  callable(): TCached  $callback
     * @return TCached
     */
    protected function cached(string $key, callable $callback): mixed
    {
        $ttl = (int) config('research.http.cache_ttl', 900);

        if ($ttl <= 0) {
            return $callback();
        }

        return Cache::remember('research:'.$this->key().':'.md5($key), $ttl, $callback);
    }

    /**
     * @param  array<string, int|float|string>  $engagement
     * @param  array<string, mixed>  $raw
     */
    protected function item(
        string $title,
        string $url,
        ?string $externalId = null,
        ?string $summary = null,
        ?string $author = null,
        ?CarbonImmutable $publishedAt = null,
        array $engagement = [],
        array $raw = [],
    ): ResearchItem {
        return new ResearchItem(
            sourceKey: $this->key(),
            title: $title,
            url: $url,
            externalId: $externalId,
            summary: $summary,
            author: $author,
            publishedAt: $publishedAt,
            engagement: $engagement,
            raw: $raw,
        );
    }
}
