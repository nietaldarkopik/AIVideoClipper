<?php

namespace App\Services\Research\Providers;

use App\Services\Research\DTOs\ResearchQuery;

/**
 * GitHub repository search. Works unauthenticated (10 req/min); a GITHUB_TOKEN
 * raises that to 30 — hence requiresCredentials() is false but the token is used
 * when present.
 *
 * Sorted by stars over a recent-creation window: "what new project is the
 * developer world starring this week" is the signal a tech channel actually wants,
 * which plain relevance search buries under long-established repos.
 */
class GitHubResearchProvider extends AbstractHttpResearchProvider
{
    private const BASE = 'https://api.github.com';

    public function key(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return 'GitHub';
    }

    public function type(): string
    {
        return 'code';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'min_stars', 'label' => 'Minimum Stars', 'type' => 'number', 'default' => 50],
            ['key' => 'created_within_days', 'label' => 'Repo dibuat dalam (hari)', 'type' => 'number', 'default' => 90],
            ['key' => 'language', 'label' => 'Bahasa Pemrograman', 'type' => 'text'],
        ];
    }

    public function search(ResearchQuery $query): array
    {
        $minStars = max(0, (int) $query->option('min_stars', 50));
        $createdWithin = max(1, (int) $query->option('created_within_days', 90));
        $codeLanguage = trim((string) $query->option('language', ''));
        $since = now()->subDays($createdWithin)->toDateString();

        $items = [];

        foreach ($query->primaryTerms(3) as $term) {
            $q = $term." stars:>={$minStars} created:>={$since}";
            if ($codeLanguage !== '') {
                $q .= ' language:'.$codeLanguage;
            }

            $payload = $this->getJson(self::BASE.'/search/repositories', [
                'q' => $q,
                'sort' => 'stars',
                'order' => 'desc',
                'per_page' => max(5, (int) ceil($query->limit / 3)),
            ], $this->headers());

            foreach ($payload['items'] ?? [] as $repo) {
                $fullName = (string) ($repo['full_name'] ?? '');
                if ($fullName === '') {
                    continue;
                }

                $items[] = $this->item(
                    // full_name + description, not the bare repo name: "awesome-thing" on
                    // its own carries almost no tokens for topic clustering.
                    title: $fullName.' — '.mb_substr((string) ($repo['description'] ?? ''), 0, 160),
                    url: (string) ($repo['html_url'] ?? ''),
                    externalId: (string) ($repo['id'] ?? ''),
                    summary: $repo['description'] ?? null,
                    author: $repo['owner']['login'] ?? null,
                    publishedAt: $this->parseDate($repo['created_at'] ?? null),
                    engagement: [
                        'score' => (int) ($repo['stargazers_count'] ?? 0),
                        'forks' => (int) ($repo['forks_count'] ?? 0),
                        'open_issues' => (int) ($repo['open_issues_count'] ?? 0),
                    ],
                    raw: ['language' => $repo['language'] ?? null, 'topics' => $repo['topics'] ?? []],
                );
            }
        }

        return array_slice($items, 0, $query->limit);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'];
        $token = config('research.providers.github.token');

        if (is_string($token) && $token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $headers;
    }
}
