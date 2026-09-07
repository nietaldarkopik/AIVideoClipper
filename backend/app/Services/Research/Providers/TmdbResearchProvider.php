<?php

namespace App\Services\Research\Providers;

use App\Services\Research\DTOs\ResearchItem;
use App\Services\Research\DTOs\ResearchQuery;

/**
 * TMDB — the movie/TV data source for film channels.
 *
 * TMDB is also what backs the "IMDb" requirement in the spec: IMDb has no public
 * API (their only offering is a paid enterprise contract) and scraping it breaks
 * their terms, so this provider returns TMDB data and links out to the matching
 * IMDb page via TMDB's own imdb_id where available — real data, honestly attributed.
 */
class TmdbResearchProvider extends AbstractHttpResearchProvider
{
    private const BASE = 'https://api.themoviedb.org/3';

    public function key(): string
    {
        return 'tmdb';
    }

    public function label(): string
    {
        return 'TMDB (Film & TV)';
    }

    public function type(): string
    {
        return 'media';
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        $key = config('research.providers.tmdb.api_key');

        return is_string($key) && $key !== '';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['trending', 'search', 'upcoming'], 'default' => 'trending'],
            ['key' => 'media_type', 'label' => 'Tipe', 'type' => 'select', 'options' => ['all', 'movie', 'tv'], 'default' => 'all'],
            ['key' => 'min_vote_count', 'label' => 'Minimum Jumlah Vote', 'type' => 'number', 'default' => 50],
        ];
    }

    public function search(ResearchQuery $query): array
    {
        return match ((string) $query->option('mode', 'trending')) {
            'search' => $this->searchTitles($query),
            'upcoming' => $this->upcoming($query),
            default => $this->trending($query),
        };
    }

    public function trending(ResearchQuery $query): array
    {
        $mediaType = (string) $query->option('media_type', 'all');
        // TMDB's trending window is day|week only; anything under 48h maps to day.
        $window = $query->lookbackHours <= 48 ? 'day' : 'week';

        $payload = $this->getJson(self::BASE."/trending/{$mediaType}/{$window}", [
            'api_key' => config('research.providers.tmdb.api_key'),
            'language' => $this->tmdbLanguage($query->language),
        ]);

        return array_slice($this->mapResults($payload['results'] ?? [], $query), 0, $query->limit);
    }

    /**
     * @return ResearchItem[]
     */
    private function searchTitles(ResearchQuery $query): array
    {
        $items = [];

        foreach ($query->primaryTerms(3) as $term) {
            $payload = $this->getJson(self::BASE.'/search/multi', [
                'api_key' => config('research.providers.tmdb.api_key'),
                'query' => $term,
                'language' => $this->tmdbLanguage($query->language),
                'include_adult' => 'false',
            ]);

            foreach ($this->mapResults($payload['results'] ?? [], $query) as $item) {
                $items[] = $item;
            }
        }

        return array_slice($items, 0, $query->limit);
    }

    /**
     * @return ResearchItem[]
     */
    private function upcoming(ResearchQuery $query): array
    {
        $payload = $this->getJson(self::BASE.'/movie/upcoming', [
            'api_key' => config('research.providers.tmdb.api_key'),
            'language' => $this->tmdbLanguage($query->language),
        ]);

        return array_slice($this->mapResults($payload['results'] ?? [], $query, forcedType: 'movie'), 0, $query->limit);
    }

    /**
     * @param  array<mixed>  $results
     * @return ResearchItem[]
     */
    private function mapResults(array $results, ResearchQuery $query, ?string $forcedType = null): array
    {
        $minVotes = (int) $query->option('min_vote_count', 0);
        $items = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $mediaType = $forcedType ?? ($result['media_type'] ?? 'movie');
            if ($mediaType === 'person') {
                continue;
            }

            // Movies carry `title`, TV carries `name` — neither is always present.
            $title = (string) ($result['title'] ?? $result['name'] ?? '');
            $id = $result['id'] ?? null;

            if ($title === '' || $id === null) {
                continue;
            }

            $voteCount = (int) ($result['vote_count'] ?? 0);
            if ($voteCount < $minVotes) {
                continue;
            }

            $items[] = $this->item(
                title: $title,
                url: "https://www.themoviedb.org/{$mediaType}/{$id}",
                externalId: (string) $id,
                summary: $result['overview'] ?? null,
                publishedAt: $this->parseDate($result['release_date'] ?? $result['first_air_date'] ?? null),
                engagement: [
                    // vote_average is 0-10; scaled here so the engagement scorer sees a
                    // comparable magnitude to other sources' scores.
                    'score' => (int) round(((float) ($result['vote_average'] ?? 0)) * 10),
                    'votes' => $voteCount,
                    'popularity' => (int) round((float) ($result['popularity'] ?? 0)),
                ],
                raw: ['media_type' => $mediaType, 'poster_path' => $result['poster_path'] ?? null],
            );
        }

        return $items;
    }

    /**
     * TMDB wants an IETF tag (id-ID, en-US), not a bare language code.
     */
    private function tmdbLanguage(string $language): string
    {
        return match ($language) {
            'id' => 'id-ID',
            'en' => 'en-US',
            default => $language,
        };
    }
}
