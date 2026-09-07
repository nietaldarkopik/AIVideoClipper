<?php

namespace App\Services\Research\Engine;

/**
 * Shared tokenization used by topic clustering, relevance scoring and duplicate
 * detection. One implementation on purpose: when clustering and dedupe tokenize
 * differently, a topic can cluster as new and then be rejected as a duplicate (or
 * worse, the reverse), and the inconsistency is invisible from the outside.
 */
class TextSignature
{
    /**
     * Words carrying no topical signal. Indonesian and English together, since a
     * channel's sources routinely mix both regardless of the channel's language.
     */
    private const STOPWORDS = [
        // Indonesian
        'yang', 'untuk', 'dengan', 'dari', 'pada', 'adalah', 'akan', 'atau', 'dan', 'ini', 'itu',
        'dalam', 'tidak', 'bisa', 'ada', 'juga', 'karena', 'saya', 'kita', 'mereka', 'sudah',
        'lebih', 'oleh', 'agar', 'saat', 'kata', 'bagaimana', 'kenapa', 'apa', 'jadi', 'buat',
        // English
        'the', 'and', 'for', 'with', 'from', 'that', 'this', 'are', 'was', 'were', 'you', 'your',
        'have', 'has', 'not', 'but', 'can', 'will', 'how', 'why', 'what', 'when', 'who', 'into',
        'over', 'about', 'after', 'before', 'their', 'there', 'here', 'its', 'been', 'more', 'new',
        'via', 'get', 'top', 'best', 'show', 'hn', 'ask',
    ];

    /**
     * @return string[] deduplicated, lowercased, stopword-free tokens
     */
    public static function tokens(string $text): array
    {
        $minLength = (int) config('research.topics.min_token_length', 3);

        // Unicode-aware split: \p{L}\p{N} keeps accented and non-Latin words intact,
        // which a plain [a-z0-9] class would shred into meaningless fragments.
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];

        $tokens = [];

        foreach ($parts as $part) {
            if (mb_strlen($part) < $minLength) {
                continue;
            }
            if (in_array($part, self::STOPWORDS, true)) {
                continue;
            }
            $tokens[$part] = true;
        }

        return array_keys($tokens);
    }

    /**
     * Jaccard similarity of two token sets, 0..1.
     *
     * Jaccard rather than cosine/overlap: it penalizes length mismatch, so a short
     * headline is not treated as identical to a long one merely because every one of
     * its few words appears somewhere in the longer text.
     *
     * @param  string[]  $a
     * @param  string[]  $b
     */
    public static function similarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($a, $b));

        if ($intersection === 0) {
            return 0.0;
        }

        $union = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : $intersection / $union;
    }

    /**
     * Stable signature for a piece of text: the most significant tokens, sorted.
     * Used as content_ideas.fingerprint and research_results.topic_key.
     */
    public static function fingerprint(string $text, int $maxTokens = 8): string
    {
        $tokens = self::tokens($text);
        sort($tokens);

        return implode('-', array_slice($tokens, 0, $maxTokens));
    }
}
