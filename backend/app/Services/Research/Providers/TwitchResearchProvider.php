<?php

namespace App\Services\Research\Providers;

use App\Services\Research\DTOs\ResearchItem;
use App\Services\Research\DTOs\ResearchQuery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Twitch Helix — which games are actually being watched right now, the strongest
 * available "is this game hot" signal for a gaming channel.
 *
 * Auth is the app-access-token (client_credentials) flow: no user consent, and the
 * token is cached until shortly before expiry so a research run does not mint a
 * new one per call.
 */
class TwitchResearchProvider extends AbstractHttpResearchProvider
{
    private const HELIX = 'https://api.twitch.tv/helix';

    private const TOKEN_URL = 'https://id.twitch.tv/oauth2/token';

    private const TOKEN_CACHE_KEY = 'research:twitch:app_token';

    public function key(): string
    {
        return 'twitch';
    }

    public function label(): string
    {
        return 'Twitch';
    }

    public function type(): string
    {
        return 'gaming';
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'language', 'label' => 'Bahasa Stream', 'type' => 'text', 'help' => 'Kode dua huruf, mis. id atau en. Kosongkan untuk semua.'],
        ];
    }

    public function search(ResearchQuery $query): array
    {
        $items = $this->topGames($query);

        foreach ($query->primaryTerms(2) as $term) {
            $payload = $this->helix('/search/categories', ['query' => $term, 'first' => 10]);

            foreach ($payload['data'] ?? [] as $game) {
                if (! is_array($game) || ($game['name'] ?? '') === '') {
                    continue;
                }

                $items[] = $this->item(
                    title: (string) $game['name'],
                    url: 'https://www.twitch.tv/directory/game/'.rawurlencode((string) $game['name']),
                    externalId: (string) ($game['id'] ?? ''),
                    summary: 'Kategori Twitch yang cocok dengan kata kunci "'.$term.'".',
                    raw: ['match' => $term],
                );
            }
        }

        return array_slice($items, 0, $query->limit);
    }

    public function trending(ResearchQuery $query): array
    {
        return array_slice($this->topGames($query), 0, $query->limit);
    }

    /**
     * @return ResearchItem[]
     */
    private function topGames(ResearchQuery $query): array
    {
        $payload = $this->helix('/games/top', ['first' => min(50, max(10, $query->limit))]);
        $items = [];

        foreach ($payload['data'] ?? [] as $rank => $game) {
            if (! is_array($game) || ($game['name'] ?? '') === '') {
                continue;
            }

            $items[] = $this->item(
                title: (string) $game['name'],
                url: 'https://www.twitch.tv/directory/game/'.rawurlencode((string) $game['name']),
                externalId: (string) ($game['id'] ?? ''),
                summary: sprintf('Peringkat #%d kategori paling banyak ditonton di Twitch saat ini.', $rank + 1),
                engagement: [
                    // Helix exposes no viewer count on /games/top, only ordering. The rank
                    // is recorded as the fact it is; no viewer number is invented.
                    'rank' => $rank + 1,
                ],
                raw: ['box_art_url' => $game['box_art_url'] ?? null],
            );
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<mixed>
     */
    private function helix(string $path, array $params): array
    {
        return $this->getJson(self::HELIX.$path, $params, [
            'Client-Id' => $this->clientId(),
            'Authorization' => 'Bearer '.$this->appToken(),
        ]);
    }

    private function appToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(50), function (): string {
            $response = Http::asForm()
                ->timeout((int) config('research.http.timeout', 20))
                ->post(self::TOKEN_URL, [
                    'client_id' => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                    'grant_type' => 'client_credentials',
                ]);

            $token = $response->json('access_token');

            if (! is_string($token) || $token === '') {
                // Surfaced as this source's error and nothing else — the engine records it
                // against Twitch and carries on with the channel's other sources.
                throw new RuntimeException('Twitch menolak kredensial client_credentials.');
            }

            return $token;
        });
    }

    private function clientId(): string
    {
        return (string) config('research.providers.twitch.client_id', '');
    }

    private function clientSecret(): string
    {
        return (string) config('research.providers.twitch.client_secret', '');
    }
}
