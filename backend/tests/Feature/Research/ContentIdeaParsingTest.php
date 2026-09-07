<?php

namespace Tests\Feature\Research;

use App\Services\AI\Concerns\GeneratesContentIdeas;
use App\Services\AI\DTOs\ContentIdeaData;
use Tests\TestCase;

/**
 * Structured-output handling for the AI idea generator. These are exactly the
 * shapes real models drift into (a markdown fence, a bare array, a missing field,
 * an invented score) — every one of them silently produced zero ideas before this
 * parsing existed.
 */
class ContentIdeaParsingTest extends TestCase
{
    private object $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new class
        {
            use GeneratesContentIdeas {
                parseIdeas as public;
                contextPayload as public;
            }
        };
    }

    public function test_parses_a_well_formed_response(): void
    {
        $raw = json_encode(['ideas' => [[
            'topic_ref' => 'T2',
            'topic' => 'Local AI coding assistant',
            'title' => 'AI Coding Assistant Lokal Tanpa Kirim Source Code ke Cloud',
            'alternative_titles' => ['Judul A', 'Judul B'],
            'short_description' => 'Deskripsi singkat.',
            'content_angle' => 'Privacy + produktivitas',
            'keywords' => ['AI', 'coding'],
            'suggested_content_type' => 'youtube',
            'suggested_format' => 'long_form',
            'originality_score' => 81,
        ]]]);

        $ideas = $this->parser->parseIdeas($raw, 5);

        $this->assertCount(1, $ideas);
        $this->assertSame('T2', $ideas[0]->topicRef);
        $this->assertSame(['Judul A', 'Judul B'], $ideas[0]->alternativeTitles);
        $this->assertSame(81, $ideas[0]->originalityScore);
    }

    public function test_parses_a_response_wrapped_in_a_markdown_fence(): void
    {
        $raw = "```json\n".json_encode(['ideas' => [['title' => 'Judul', 'topic' => 'Topik']]])."\n```";

        $this->assertCount(1, $this->parser->parseIdeas($raw, 5));
    }

    public function test_parses_a_bare_array_response(): void
    {
        // Models drift between {"ideas":[...]} and a bare [...] even with a schema in
        // the prompt; rejecting the bare form would discard a usable response.
        $raw = json_encode([['title' => 'Judul', 'topic' => 'Topik']]);

        $this->assertCount(1, $this->parser->parseIdeas($raw, 5));
    }

    public function test_an_invalid_response_yields_no_ideas_rather_than_throwing(): void
    {
        $this->assertSame([], $this->parser->parseIdeas('not json at all', 5));
        $this->assertSame([], $this->parser->parseIdeas('', 5));
        $this->assertSame([], $this->parser->parseIdeas(null, 5));
    }

    public function test_an_idea_without_a_title_is_dropped_not_invented(): void
    {
        $raw = json_encode(['ideas' => [
            ['topic' => 'Topik tanpa judul'],
            ['title' => 'Judul valid'],
        ]]);

        $ideas = $this->parser->parseIdeas($raw, 5);

        $this->assertCount(1, $ideas);
        $this->assertSame('Judul valid', $ideas[0]->title);
    }

    public function test_missing_optional_fields_fall_back_safely(): void
    {
        $ideas = $this->parser->parseIdeas(json_encode(['ideas' => [['title' => 'Cuma Judul']]]), 5);

        $idea = $ideas[0];
        // Topic falls back to the title rather than staying empty.
        $this->assertSame('Cuma Judul', $idea->topic);
        $this->assertSame([], $idea->keywords);
        $this->assertNull($idea->shortDescription);
        // No score claimed means none is fabricated — the engine then uses the measured
        // originality from DuplicateDetector.
        $this->assertNull($idea->originalityScore);
    }

    public function test_the_requested_count_is_never_exceeded(): void
    {
        $raw = json_encode(['ideas' => array_map(fn (int $i) => ['title' => "Judul {$i}"], range(1, 10))]);

        $this->assertCount(3, $this->parser->parseIdeas($raw, 3));
    }

    public function test_out_of_range_and_non_numeric_scores_are_coerced(): void
    {
        $ideas = $this->parser->parseIdeas(json_encode(['ideas' => [
            ['title' => 'A', 'originality_score' => 900],
            ['title' => 'B', 'originality_score' => -5],
            ['title' => 'C', 'originality_score' => 'sangat orisinal'],
        ]]), 5);

        $this->assertSame(100, $ideas[0]->originalityScore);
        $this->assertSame(0, $ideas[1]->originalityScore);
        $this->assertNull($ideas[2]->originalityScore);
    }

    public function test_non_string_entries_in_list_fields_are_discarded(): void
    {
        $idea = ContentIdeaData::fromArray([
            'title' => 'Judul',
            'keywords' => ['ok', 123, null, ['nested'], '  ', 'juga ok'],
        ]);

        $this->assertSame(['ok', 'juga ok'], $idea->keywords);
    }

    public function test_the_context_payload_keeps_unicode_readable_for_the_model(): void
    {
        $payload = $this->parser->contextPayload(['channel_name' => 'Ekonomi & Keuangan', 'niche' => 'çöp']);

        // Escaped \uXXXX sequences measurably degrade output quality and waste tokens.
        $this->assertStringContainsString('Ekonomi & Keuangan', $payload);
        $this->assertStringNotContainsString('\\u', $payload);
    }
}
