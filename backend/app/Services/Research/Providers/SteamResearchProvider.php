<?php

namespace App\Services\Research\Providers;

use App\Services\Research\DTOs\ResearchItem;
use App\Services\Research\DTOs\ResearchQuery;
use Carbon\CarbonImmutable;

/**
 * Steam's public storefront endpoints — no key.
 *
 * Two complementary gaming signals: featuredcategories (what Valve is promoting /
 * what is newly released and selling) and the per-app news feed for games the
 * channel explicitly tracks.
 */
class SteamResearchProvider extends AbstractHttpResearchProvider
{
    private const STORE = 'https://store.steampowered.com/api';

    private const NEWS = 'https://api.steampowered.com/ISteamNews/GetNewsForApp/v2';

    public function key(): string
    {
        return 'steam';
    }

    public function label(): string
    {
        return 'Steam';
    }

    public function type(): string
    {
        return 'gaming';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'app_ids', 'label' => 'Steam App ID yang Diikuti', 'type' => 'list', 'help' => 'Opsional. Mengambil berita resmi tiap game.'],
            ['key' => 'region', 'label' => 'Region', 'type' => 'text', 'default' => 'ID'],
        ];
    }

    public function search(ResearchQuery $query): array
    {
        $items = $this->featured($query);

        foreach ($query->optionList('app_ids') as $appId) {
            if (! ctype_digit($appId)) {
                continue;
            }

            foreach ($this->newsForApp($appId) as $item) {
                $items[] = $item;
            }
        }

        return array_slice($items, 0, $query->limit);
    }

    public function trending(ResearchQuery $query): array
    {
        return array_slice($this->featured($query), 0, $query->limit);
    }

    /**
     * @return ResearchItem[]
     */
    private function featured(ResearchQuery $query): array
    {
        $payload = $this->getJson(self::STORE.'/featuredcategories', [
            'cc' => strtoupper((string) ($query->option('region') ?: $query->region)),
            'l' => 'english',
        ]);

        $items = [];

        // Each category is a separate object with its own `items` list; specials and
        // new releases are the two that actually signal "worth covering right now".
        foreach (['specials', 'new_releases', 'top_sellers', 'coming_soon'] as $category) {
            foreach ($payload[$category]['items'] ?? [] as $game) {
                if (! is_array($game) || ($game['name'] ?? '') === '' || ! isset($game['id'])) {
                    continue;
                }

                $items[] = $this->item(
                    title: (string) $game['name'],
                    url: 'https://store.steampowered.com/app/'.$game['id'],
                    externalId: (string) $game['id'],
                    summary: 'Steam '.str_replace('_', ' ', $category),
                    engagement: array_filter([
                        // Only a discount percentage is exposed here, no player counts —
                        // recorded as-is under its own key rather than dressed up as a score.
                        'discount_percent' => isset($game['discount_percent']) ? (int) $game['discount_percent'] : null,
                    ], fn ($value) => $value !== null),
                    raw: ['category' => $category, 'header_image' => $game['header_image'] ?? null],
                );
            }
        }

        return $items;
    }

    /**
     * @return ResearchItem[]
     */
    private function newsForApp(string $appId): array
    {
        $payload = $this->getJson(self::NEWS, [
            'appid' => $appId,
            'count' => 5,
            'maxlength' => 500,
            'format' => 'json',
        ]);

        $items = [];

        foreach ($payload['appnews']['newsitems'] ?? [] as $news) {
            if (! is_array($news) || ($news['title'] ?? '') === '') {
                continue;
            }

            $items[] = $this->item(
                title: (string) $news['title'],
                url: (string) ($news['url'] ?? ''),
                externalId: (string) ($news['gid'] ?? ''),
                summary: isset($news['contents']) ? mb_substr(strip_tags((string) $news['contents']), 0, 600) : null,
                author: $news['author'] ?? null,
                publishedAt: isset($news['date']) ? CarbonImmutable::createFromTimestampUTC((int) $news['date']) : null,
                raw: ['appid' => $appId, 'feedlabel' => $news['feedlabel'] ?? null],
            );
        }

        return $items;
    }
}
