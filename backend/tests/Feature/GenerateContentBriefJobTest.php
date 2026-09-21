<?php

namespace Tests\Feature;

use App\Jobs\GenerateContentBriefJob;
use App\Models\ContentBrief;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateContentBriefJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_pipeline_completes_with_mock_providers(): void
    {
        $user = User::factory()->create();
        $brief = $user->contentBriefs()->create(['topic' => 'Tren AI', 'status' => ContentBrief::STATUS_PENDING]);

        app()->call([new GenerateContentBriefJob($brief->id), 'handle']);

        $brief->refresh();
        $this->assertSame(ContentBrief::STATUS_COMPLETED, $brief->status);
        $this->assertSame(100, $brief->progress);
        $this->assertNotEmpty($brief->sources);
        $this->assertNotEmpty($brief->candidate_videos);
        $this->assertNotEmpty($brief->narrative_title);
        $this->assertNotEmpty($brief->narrative_full_script);
        $this->assertNotNull($brief->finished_at);
    }

    public function test_script_only_regenerates_narrative_without_touching_sources_or_videos(): void
    {
        $user = User::factory()->create();
        $brief = $user->contentBriefs()->create([
            'topic' => 'Tren AI',
            'status' => ContentBrief::STATUS_COMPLETED,
            'sources' => [['title' => 'A', 'url' => 'https://a.test', 'content_excerpt' => 'isi artikel']],
            'candidate_videos' => [['title' => 'Video A', 'url' => 'https://youtube.com/watch?v=a', 'platform' => 'youtube', 'thumbnail_url' => null]],
        ]);

        app()->call([new GenerateContentBriefJob($brief->id, scriptOnly: true), 'handle']);

        $brief->refresh();
        $this->assertSame(ContentBrief::STATUS_COMPLETED, $brief->status);
        $this->assertCount(1, $brief->sources);
        $this->assertSame('https://a.test', $brief->sources[0]['url']);
        $this->assertCount(1, $brief->candidate_videos);
        $this->assertNotEmpty($brief->narrative_full_script);
    }

    public function test_cancellation_flag_stops_the_job_before_it_completes(): void
    {
        $user = User::factory()->create();
        $brief = $user->contentBriefs()->create([
            'topic' => 'Tren AI',
            'status' => ContentBrief::STATUS_PENDING,
            'cancel_requested' => true,
        ]);

        app()->call([new GenerateContentBriefJob($brief->id), 'handle']);

        $brief->refresh();
        $this->assertSame(ContentBrief::STATUS_CANCELLED, $brief->status);
        $this->assertEmpty($brief->sources);
    }
}
