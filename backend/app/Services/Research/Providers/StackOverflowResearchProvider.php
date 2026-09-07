<?php

namespace App\Services\Research\Providers;

use App\Services\Research\DTOs\ResearchQuery;
use Carbon\CarbonImmutable;

/**
 * Stack Exchange API v2.3 against the stackoverflow site — public, no key
 * (a key only raises the daily quota, which a once-a-day research run never
 * approaches).
 *
 * The signal here is "what are developers actually stuck on right now", so this
 * sorts by votes within the lookback window rather than by activity.
 */
class StackOverflowResearchProvider extends AbstractHttpResearchProvider
{
    private const BASE = 'https://api.stackexchange.com/2.3';

    public function key(): string
    {
        return 'stackoverflow';
    }

    public function label(): string
    {
        return 'Stack Overflow';
    }

    public function type(): string
    {
        return 'code';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'min_score', 'label' => 'Minimum Score', 'type' => 'number', 'default' => 3],
            ['key' => 'tagged', 'label' => 'Tag', 'type' => 'list'],
        ];
    }

    public function search(ResearchQuery $query): array
    {
        $minScore = (int) $query->option('min_score', 0);
        $fromDate = CarbonImmutable::now()->subHours(max(1, $query->lookbackHours))->getTimestamp();
        $tagged = implode(';', $query->optionList('tagged'));
        $items = [];

        foreach ($query->primaryTerms(3) as $term) {
            $params = [
                'site' => 'stackoverflow',
                'q' => $term,
                'sort' => 'votes',
                'order' => 'desc',
                'fromdate' => $fromDate,
                'pagesize' => max(5, (int) ceil($query->limit / 3)),
                // /search/advanced returns a trimmed default field set; this filter adds
                // the question body excerpt, without which every item would have a null
                // summary and score poorly on relevance.
                'filter' => 'withbody',
            ];

            if ($tagged !== '') {
                $params['tagged'] = $tagged;
            }

            $payload = $this->getJson(self::BASE.'/search/advanced', $params);

            foreach ($payload['items'] ?? [] as $question) {
                $score = (int) ($question['score'] ?? 0);
                if ($score < $minScore || ($question['title'] ?? '') === '') {
                    continue;
                }

                $items[] = $this->item(
                    title: $this->decode((string) $question['title']),
                    url: (string) ($question['link'] ?? ''),
                    externalId: (string) ($question['question_id'] ?? ''),
                    summary: isset($question['body']) ? mb_substr(strip_tags((string) $question['body']), 0, 600) : null,
                    author: $question['owner']['display_name'] ?? null,
                    publishedAt: isset($question['creation_date']) ? CarbonImmutable::createFromTimestampUTC((int) $question['creation_date']) : null,
                    engagement: [
                        'score' => $score,
                        'comments' => (int) ($question['answer_count'] ?? 0),
                        'views' => (int) ($question['view_count'] ?? 0),
                    ],
                    raw: ['tags' => $question['tags'] ?? [], 'is_answered' => $question['is_answered'] ?? null],
                );
            }
        }

        return array_slice($items, 0, $query->limit);
    }
}
