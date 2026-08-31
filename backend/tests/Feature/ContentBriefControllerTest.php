<?php

namespace Tests\Feature;

use App\Jobs\GenerateContentBriefJob;
use App\Models\ContentBrief;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContentBriefControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/content-briefs', ['topic' => 'Test topic'])
            ->assertUnauthorized();
    }

    public function test_store_rejects_empty_topic(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/content-briefs', ['topic' => ''])
            ->assertUnprocessable();
    }

    public function test_store_creates_brief_and_dispatches_the_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/content-briefs', [
            'topic' => 'Tren AI di Indonesia',
            'source_platform' => 'youtube',
            'source_trending_title' => 'AI makin viral',
            'source_trending_url' => 'https://youtube.com/watch?v=abc',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.topic', 'Tren AI di Indonesia');
        $response->assertJsonPath('data.status', ContentBrief::STATUS_PENDING);

        $brief = ContentBrief::firstOrFail();
        $this->assertSame($user->id, $brief->user_id);

        Queue::assertPushed(GenerateContentBriefJob::class, fn ($job) => $job->contentBriefId === $brief->id && ! $job->scriptOnly);
    }

    public function test_users_cannot_see_each_others_briefs(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $brief = $owner->contentBriefs()->create(['topic' => 'Topic', 'status' => ContentBrief::STATUS_COMPLETED]);

        $this->actingAs($stranger)
            ->getJson("/api/content-briefs/{$brief->id}")
            ->assertNotFound();

        $this->actingAs($owner)
            ->getJson("/api/content-briefs/{$brief->id}")
            ->assertOk();
    }

    public function test_regenerate_script_rejects_when_still_active(): void
    {
        $user = User::factory()->create();
        $brief = $user->contentBriefs()->create(['topic' => 'Topic', 'status' => ContentBrief::STATUS_SEARCHING]);

        $this->actingAs($user)
            ->postJson("/api/content-briefs/{$brief->id}/regenerate-script")
            ->assertUnprocessable();
    }

    public function test_regenerate_script_rejects_when_no_sources(): void
    {
        $user = User::factory()->create();
        $brief = $user->contentBriefs()->create(['topic' => 'Topic', 'status' => ContentBrief::STATUS_COMPLETED]);

        $this->actingAs($user)
            ->postJson("/api/content-briefs/{$brief->id}/regenerate-script")
            ->assertUnprocessable();
    }

    public function test_regenerate_script_resets_narrative_and_dispatches_script_only_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $brief = $user->contentBriefs()->create([
            'topic' => 'Topic',
            'status' => ContentBrief::STATUS_COMPLETED,
            'sources' => [['title' => 'A', 'url' => 'https://a.test', 'content_excerpt' => 'x']],
            'narrative_title' => 'Old title',
            'narrative_full_script' => 'Old script',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/content-briefs/{$brief->id}/regenerate-script")
            ->assertOk();

        $response->assertJsonPath('data.status', ContentBrief::STATUS_GENERATING_SCRIPT);
        $this->assertNull($brief->fresh()->narrative_title);

        Queue::assertPushed(GenerateContentBriefJob::class, fn ($job) => $job->contentBriefId === $brief->id && $job->scriptOnly);
    }

    public function test_destroy_flags_cancellation_instead_of_deleting_an_active_brief(): void
    {
        $user = User::factory()->create();
        $brief = $user->contentBriefs()->create(['topic' => 'Topic', 'status' => ContentBrief::STATUS_GENERATING_SCRIPT]);

        $this->actingAs($user)
            ->deleteJson("/api/content-briefs/{$brief->id}")
            ->assertUnprocessable();

        $this->assertTrue($brief->fresh()->cancel_requested);
        $this->assertNotNull(ContentBrief::find($brief->id));
    }

    public function test_destroy_deletes_a_finished_brief(): void
    {
        $user = User::factory()->create();
        $brief = $user->contentBriefs()->create(['topic' => 'Topic', 'status' => ContentBrief::STATUS_COMPLETED]);

        $this->actingAs($user)
            ->deleteJson("/api/content-briefs/{$brief->id}")
            ->assertOk();

        $this->assertNull(ContentBrief::find($brief->id));
    }
}
