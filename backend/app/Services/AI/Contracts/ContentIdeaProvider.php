<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\DTOs\ContentIdeaData;

interface ContentIdeaProvider
{
    /**
     * Turn retrieved research into channel-specific content ideas.
     *
     * $context is the reusable prompt input described in the spec, already
     * assembled by ContentIdeaPromptBuilder:
     *   channel_niche, sub_niches, target_audience, content_style, content_types,
     *   content_formats, keywords, excluded_keywords, language, platform,
     *   research_results, previous_content, current_date
     *
     * Implementations must return at most $count ideas and must not fabricate
     * sources or metrics — research_results is the only factual input they get, and
     * anything not grounded in it belongs in the editorial fields (angle, hook),
     * never presented as retrieved fact.
     *
     * @param  array<string, mixed>  $context
     * @return ContentIdeaData[]
     */
    public function generateIdeas(array $context, int $count): array;
}
