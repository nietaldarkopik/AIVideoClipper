<?php

namespace App\Services\AI\Concerns;

use App\Services\AI\DTOs\ContentIdeaData;

/**
 * Shared response handling for every ContentIdeaProvider that talks to a chat
 * model. Only the transport differs between OpenAI, 9Router and Gemini; the
 * parsing rules must not, or a model swap would quietly change which malformed
 * responses get accepted.
 */
trait GeneratesContentIdeas
{
    use ParsesJsonResponses;

    /**
     * @return ContentIdeaData[]
     */
    protected function parseIdeas(?string $raw, int $count): array
    {
        $parsed = $this->extractJsonObject($raw);

        if ($parsed === null) {
            return [];
        }

        // Accept both {"ideas":[...]} and a bare [...] — models drift between the two
        // even with a schema in the prompt, and rejecting the bare array would throw
        // away a perfectly usable response.
        $rawIdeas = $parsed['ideas'] ?? (array_is_list($parsed) ? $parsed : []);

        if (! is_array($rawIdeas)) {
            return [];
        }

        $ideas = [];

        foreach ($rawIdeas as $rawIdea) {
            if (! is_array($rawIdea)) {
                continue;
            }

            $idea = ContentIdeaData::fromArray($rawIdea);

            if ($idea !== null) {
                $ideas[] = $idea;
            }

            if (count($ideas) >= $count) {
                break;
            }
        }

        return $ideas;
    }

    /**
     * The user-message payload: the assembled context as pretty JSON.
     *
     * JSON_UNESCAPED_UNICODE matters — without it Indonesian titles reach the model
     * as \uXXXX escapes, which measurably degrades output quality and wastes tokens.
     *
     * @param  array<string, mixed>  $context
     */
    protected function contextPayload(array $context): string
    {
        return (string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
