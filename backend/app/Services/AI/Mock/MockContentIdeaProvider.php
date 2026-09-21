<?php

namespace App\Services\AI\Mock;

use App\Services\AI\Contracts\ContentIdeaProvider;
use App\Services\AI\DTOs\ContentIdeaData;

/**
 * Zero-API-key default, like every other mock provider in this app.
 *
 * Deliberately derives everything from the REAL research context it is handed
 * rather than emitting canned topics: that keeps the whole pipeline — retrieval,
 * clustering, scoring, dedupe, persistence, evidence — honestly exercisable with
 * no credentials, and means a mock idea still points at genuine source URLs.
 */
class MockContentIdeaProvider implements ContentIdeaProvider
{
    public function generateIdeas(array $context, int $count): array
    {
        $topics = $context['research_results'] ?? [];

        if (! is_array($topics) || $topics === []) {
            return [];
        }

        $language = (string) ($context['language'] ?? 'id');
        $contentTypes = $this->list($context['content_types'] ?? []);
        $formats = $this->list($context['content_formats'] ?? []);
        $styles = $this->list($context['content_style'] ?? []);
        $ideas = [];

        foreach (array_slice($topics, 0, $count) as $index => $topic) {
            if (! is_array($topic)) {
                continue;
            }

            $label = (string) ($topic['topic'] ?? '');
            if ($label === '') {
                continue;
            }

            $style = $styles[$index % max(1, count($styles))] ?? 'Commentary';
            $sourceNames = implode(', ', (array) ($topic['signals']['source_names'] ?? []));

            $ideas[] = new ContentIdeaData(
                topic: $label,
                title: $this->title($label, $style, $language),
                alternativeTitles: [
                    $this->prefix($language, 'alt_a').$label,
                    $this->prefix($language, 'alt_b').$label,
                ],
                shortDescription: $this->sentence($language, 'description', $label),
                contentAngle: $style.' — '.($context['channel_niche'] ?? 'general'),
                whyThisTopic: $this->sentence($language, 'why', $sourceNames ?: 'riset'),
                targetAudience: $context['target_audience'] ?? null,
                keywords: array_slice($this->list($context['keywords'] ?? []), 0, 5),
                suggestedContentType: $contentTypes[0] ?? 'video',
                suggestedFormat: $formats[0] ?? 'long_form',
                // Left null: the mock has no editorial judgement to offer, so the engine
                // uses DuplicateDetector's measured originality instead of a fake number.
                originalityScore: null,
                topicRef: (string) ($topic['topic_ref'] ?? ''),
            );
        }

        return $ideas;
    }

    private function title(string $label, string $style, string $language): string
    {
        return $language === 'id'
            ? sprintf('%s: %s', $style, $label)
            : sprintf('%s: %s', $style, $label);
    }

    private function prefix(string $language, string $variant): string
    {
        return match ([$language, $variant]) {
            ['id', 'alt_a'] => 'Kenapa Ramai Dibicarakan — ',
            ['id', 'alt_b'] => 'Yang Perlu Kamu Tahu Soal ',
            [$language, 'alt_a'] => 'Why Everyone Is Talking About ',
            default => 'What You Should Know About ',
        };
    }

    private function sentence(string $language, string $kind, string $subject): string
    {
        if ($language === 'id') {
            return $kind === 'why'
                ? sprintf('Topik ini muncul di beberapa sumber riset (%s) dalam periode terakhir.', $subject)
                : sprintf('Pembahasan singkat mengenai %s berdasarkan hasil riset terbaru.', $subject);
        }

        return $kind === 'why'
            ? sprintf('This topic surfaced across several research sources (%s) recently.', $subject)
            : sprintf('A short breakdown of %s based on the latest research.', $subject);
    }

    /**
     * @return string[]
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
