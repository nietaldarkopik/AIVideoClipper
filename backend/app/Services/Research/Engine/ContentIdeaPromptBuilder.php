<?php

namespace App\Services\Research\Engine;

use App\Models\ContentChannel;

/**
 * Assembles the reusable AI prompt input (spec section 17) from a channel and its
 * scored topics.
 *
 * Lives here rather than inside each ContentIdeaProvider so every provider —
 * mock, OpenAI, 9Router — receives byte-identical context and only differs in how
 * it calls its model.
 */
class ContentIdeaPromptBuilder
{
    /**
     * @param  ResearchTopic[]  $topics
     * @param  string[]  $previousTitles
     * @return array<string, mixed>
     */
    public function build(ContentChannel $channel, array $topics, array $previousTitles): array
    {
        return [
            'channel_name' => $channel->name,
            'platform' => $channel->platform?->name ?? 'YouTube',
            'channel_niche' => $channel->niche,
            'sub_niches' => $this->list($channel->sub_niches),
            'target_audience' => $channel->target_audience,
            'content_style' => $this->list($channel->content_style),
            'content_types' => $this->list($channel->content_types),
            'content_formats' => $this->list($channel->content_formats),
            'tone' => $channel->tone,
            'hook_styles' => $this->list($channel->hook_styles),
            'keywords' => $this->list($channel->keywords),
            'excluded_keywords' => $this->list($channel->excluded_keywords),
            'language' => $channel->language ?: 'id',
            'research_results' => $this->researchResults($topics),
            'previous_content' => $previousTitles,
            'current_date' => now($channel->timezone ?: config('app.timezone'))->toDateString(),
        ];
    }

    /**
     * The factual half of the prompt. Scores are included so the model knows which
     * topics the retrieval stage rated highest, and sources are listed with real
     * URLs so a generated idea can cite them instead of inventing citations.
     *
     * @param  ResearchTopic[]  $topics
     * @return array<int, array<string, mixed>>
     */
    private function researchResults(array $topics): array
    {
        $results = [];

        foreach ($topics as $index => $topic) {
            $sources = [];

            foreach ($topic->evidence(5) as $item) {
                $sources[] = array_filter([
                    'source' => $item->sourceKey,
                    'title' => $item->title,
                    'url' => $item->url,
                    'published_at' => $item->publishedAt?->toDateString(),
                    'summary' => $item->summary !== null ? mb_substr($item->summary, 0, 300) : null,
                    'engagement' => $item->engagement !== [] ? $item->engagement : null,
                ], fn ($value) => $value !== null);
            }

            $results[] = [
                // A stable per-run reference the model echoes back as `topic_ref`, which
                // is how a returned idea is mapped to the topic (and therefore to the
                // real evidence) it was built from.
                'topic_ref' => 'T'.($index + 1),
                'topic' => $topic->label,
                'signals' => [
                    'sources_covering_it' => $topic->distinctSourceCount(),
                    'source_names' => $topic->sourceKeys(),
                    'trend_score' => $topic->trendScore,
                    'relevance_score' => $topic->relevanceScore,
                    'freshness_score' => $topic->freshnessScore,
                    'cross_source_score' => $topic->crossSourceScore,
                ],
                'sources' => $sources,
            ];
        }

        return $results;
    }

    /**
     * @return string[]
     */
    private function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_string($item) ? trim($item) : null,
            $value,
        ), fn ($item) => is_string($item) && $item !== ''));
    }

    /**
     * The shared system prompt. Identical across providers so switching models does
     * not silently change the editorial rules.
     */
    public static function systemPrompt(int $count, string $language): string
    {
        $languageName = match ($language) {
            'id' => 'Bahasa Indonesia',
            'en' => 'English',
            default => $language,
        };

        return <<<PROMPT
You are a content strategist generating daily content ideas for ONE specific social
media channel, based on real research data that was just retrieved.

Rules:
- Generate at most {$count} ideas. Fewer is fine if the research does not support more.
- Every idea MUST be grounded in the provided research_results. Do NOT invent topics,
  URLs, statistics, view counts, or sources. If you reference a source, it must be one
  of the URLs given to you.
- Each idea must fit THIS channel's niche, audience, content style and format. The same
  research seen by a different channel would produce a different angle — write for this
  channel only.
- Do not repeat anything in previous_content. Vary the angle if the topic is close.
- Respect excluded_keywords: never propose an idea about them.
- Write titles, descriptions and angles in {$languageName}.
- Set "topic_ref" on every idea to the topic_ref of the research topic it came from.

Respond with ONLY a JSON object of this exact shape, no prose and no markdown fence:

{
  "ideas": [
    {
      "topic_ref": "T1",
      "topic": "...",
      "title": "...",
      "alternative_titles": ["...", "..."],
      "short_description": "...",
      "content_angle": "...",
      "why_this_topic": "...",
      "target_audience": "...",
      "keywords": ["..."],
      "suggested_content_type": "...",
      "suggested_format": "...",
      "originality_score": 80
    }
  ]
}

originality_score is your editorial judgement (0-100) of how fresh this angle is
compared to previous_content. Do not output trend, relevance or engagement scores:
those are computed from the retrieved data, not estimated by you.
PROMPT;
    }
}
