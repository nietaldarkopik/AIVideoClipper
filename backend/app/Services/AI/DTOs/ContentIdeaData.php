<?php

namespace App\Services\AI\DTOs;

/**
 * One AI-generated content idea, already validated into a known shape.
 *
 * The AI supplies the editorial fields (title, angle, why). The three retrieval
 * scores — trend, relevance, cross_source — are NOT taken from the model: they
 * are computed by TopicScorer from real retrieved data, because a language model
 * asked to rate how much a topic is trending will happily produce a confident
 * number with nothing behind it (spec section 22). Only originality is accepted
 * from the model, as an editorial judgement, and even that is reconciled against
 * DuplicateDetector.
 */
class ContentIdeaData
{
    /**
     * @param  string[]  $alternativeTitles
     * @param  string[]  $keywords
     */
    public function __construct(
        public readonly string $topic,
        public readonly string $title,
        public readonly array $alternativeTitles = [],
        public readonly ?string $shortDescription = null,
        public readonly ?string $contentAngle = null,
        public readonly ?string $whyThisTopic = null,
        public readonly ?string $targetAudience = null,
        public readonly array $keywords = [],
        public readonly ?string $suggestedContentType = null,
        public readonly ?string $suggestedFormat = null,
        public readonly ?int $originalityScore = null,
        /**
         * Echoed back by the model ("T1", "T2"...) to say which research topic this
         * idea came from. Null when the model omitted it — the engine then falls back
         * to matching by title similarity rather than dropping the idea.
         */
        public readonly ?string $topicRef = null,
    ) {}

    /**
     * Builds from a decoded AI JSON object, coercing every field and dropping
     * anything unusable. Returns null when the object has no title at all — an
     * idea without a title is not salvageable, and silently inventing one would
     * hide a broken prompt or a model that ignored the schema.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $title = self::str($payload['title'] ?? null);

        if ($title === null) {
            return null;
        }

        $originality = $payload['originality_score'] ?? null;

        return new self(
            // Fall back to the title when the model omits the topic: the topic is a
            // grouping label, and the title always implies one.
            topic: self::str($payload['topic'] ?? null) ?? $title,
            title: $title,
            alternativeTitles: self::strList($payload['alternative_titles'] ?? []),
            shortDescription: self::str($payload['short_description'] ?? null),
            contentAngle: self::str($payload['content_angle'] ?? null),
            whyThisTopic: self::str($payload['why_this_topic'] ?? null),
            targetAudience: self::str($payload['target_audience'] ?? null),
            keywords: self::strList($payload['keywords'] ?? []),
            suggestedContentType: self::str($payload['suggested_content_type'] ?? null),
            suggestedFormat: self::str($payload['suggested_format'] ?? null),
            originalityScore: is_numeric($originality) ? max(0, min(100, (int) $originality)) : null,
            topicRef: self::str($payload['topic_ref'] ?? null),
        );
    }

    private static function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return string[]
     */
    private static function strList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => self::str($item),
            $value,
        ), fn ($item) => $item !== null));
    }
}
