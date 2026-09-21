<?php

namespace Tests\Feature\Research;

use App\Models\Clip;
use App\Models\ContentChannel;
use App\Models\Platform;
use App\Models\Project;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Research\Engine\AudiencePerformanceProfile;
use App\Services\Research\Engine\ResearchTopic;
use App\Services\Research\Engine\TopicScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The research engine learning from what actually performed.
 *
 * Two properties matter most here and both are about NOT inventing data: a user
 * with no measured publishing history must score exactly as they did before this
 * feature existed, and the "we don't know" state must stay distinct from a real
 * zero. See AudiencePerformanceProfile and TopicScorer::performance.
 */
class PerformanceFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function channelFor(User $user): ContentChannel
    {
        $platform = Platform::create(['key' => 'youtube', 'name' => 'YouTube', 'type' => 'video']);

        return ContentChannel::create([
            'user_id' => $user->id,
            'platform_id' => $platform->id,
            'name' => 'Test Channel',
            'language' => 'id',
            'timezone' => 'Asia/Jakarta',
            'niche' => 'Teknologi',
            'keywords' => ['docker', 'linux'],
            'ideas_per_run' => 3,
        ]);
    }

    private function publishClip(User $user, string $title, int $views): SocialPost
    {
        $account = SocialAccount::create([
            'user_id' => $user->id, 'platform' => 'tiktok',
            'account_name' => 'Ch', 'status' => SocialAccount::STATUS_CONNECTED,
        ]);
        $project = $user->projects()->create([
            'title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now(),
        ]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 20]);
        $clip = Clip::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en',
            'title' => $title, 'status' => Clip::STATUS_COMPLETED, 'output_path' => 'clips/x.mp4',
        ]);

        return SocialPost::create([
            'clip_id' => $clip->id, 'social_account_id' => $account->id, 'platform' => 'tiktok',
            'status' => SocialPost::STATUS_PUBLISHED, 'published_at' => now()->subDay(),
            'external_post_id' => 'ext-'.uniqid(),
            'metrics' => ['views' => $views, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'saves' => 0],
        ]);
    }

    private function topic(string $label, array $tokens): ResearchTopic
    {
        return new ResearchTopic($label, $tokens);
    }

    public function test_no_profile_is_built_without_enough_measured_history(): void
    {
        $user = User::factory()->create();
        // Four measured posts: below the floor, where one lucky clip would define
        // the entire profile.
        foreach (range(1, 4) as $i) {
            $this->publishClip($user, "Tutorial docker {$i}", 1000);
        }

        $this->assertNull(app(AudiencePerformanceProfile::class)->build($user));
    }

    public function test_a_profile_is_built_once_there_is_enough_history(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 6) as $i) {
            $this->publishClip($user, "Tutorial docker kubernetes {$i}", 1000 * $i);
        }

        $profile = app(AudiencePerformanceProfile::class)->build($user);

        $this->assertIsArray($profile);
        $this->assertArrayHasKey('docker', $profile);
    }

    public function test_posts_with_no_measured_views_never_form_a_profile(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 8) as $i) {
            $this->publishClip($user, "Tutorial docker {$i}", 0);
        }

        // Synced but all zero is not the same as "performed well at zero" — there
        // is no top performer to measure the rest against.
        $this->assertNull(app(AudiencePerformanceProfile::class)->build($user));
    }

    public function test_without_a_profile_priority_is_unchanged(): void
    {
        $user = User::factory()->create();
        $channel = $this->channelFor($user);
        $scorer = app(TopicScorer::class);

        $withoutProfile = $scorer->score([$this->topic('Docker tips', ['docker', 'tips'])], $channel, null);

        // The component is skipped entirely rather than scored 0 — a zero would
        // consume its weight and drag every topic down for a user who simply
        // hasn't published anything measurable yet.
        $this->assertNull($withoutProfile[0]->performanceScore);

        $baseline = $scorer->score([$this->topic('Docker tips', ['docker', 'tips'])], $channel);
        $this->assertSame($baseline[0]->priorityScore, $withoutProfile[0]->priorityScore);
    }

    public function test_a_topic_resembling_past_hits_scores_above_one_that_does_not(): void
    {
        $user = User::factory()->create();
        $channel = $this->channelFor($user);

        // A clear top performer about kubernetes, and a long tail about something
        // else entirely.
        $this->publishClip($user, 'Kubernetes cluster autoscaling walkthrough', 500_000);
        foreach (range(1, 6) as $i) {
            $this->publishClip($user, "Resep masakan rendang {$i}", 500);
        }

        $profile = app(AudiencePerformanceProfile::class)->build($user);

        $scored = app(TopicScorer::class)->score([
            $this->topic('Kubernetes autoscaling', ['kubernetes', 'autoscaling', 'cluster']),
            $this->topic('Unrelated topic', ['fotografi', 'lensa', 'kamera']),
        ], $channel, $profile);

        $familiar = collect($scored)->firstWhere('label', 'Kubernetes autoscaling');
        $unfamiliar = collect($scored)->firstWhere('label', 'Unrelated topic');

        $this->assertGreaterThan($unfamiliar->performanceScore, $familiar->performanceScore);
        // Zero, not null: with a profile in hand "nothing here resembles what
        // worked" is a real reading, unlike the no-history case above.
        $this->assertSame(0, $unfamiliar->performanceScore);
    }

    public function test_a_project_records_where_it_came_from(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/projects', [
            'title' => 'From an idea',
            'origin_type' => Project::ORIGIN_CONTENT_IDEA,
            'origin_id' => 42,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('projects', [
            'id' => $response->json('data.id'),
            'origin_type' => Project::ORIGIN_CONTENT_IDEA,
            'origin_id' => 42,
        ]);
    }

    public function test_an_unknown_origin_type_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/projects', ['title' => 'X', 'origin_type' => 'somewhere_else'])
            ->assertStatus(422);
    }

    public function test_an_origin_id_without_a_type_is_discarded(): void
    {
        $user = User::factory()->create();

        // An id pointing at nothing would quietly corrupt any later attribution
        // query, so it's dropped rather than stored half-formed.
        $response = $this->actingAs($user)->postJson('/api/projects', ['title' => 'X', 'origin_id' => 99]);

        $response->assertCreated();
        $this->assertDatabaseHas('projects', [
            'id' => $response->json('data.id'),
            'origin_type' => null,
            'origin_id' => null,
        ]);
    }

    public function test_a_plain_upload_still_creates_a_project_with_no_origin(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/projects', ['title' => 'Manual upload']);

        $response->assertCreated();
        $this->assertNull($response->json('data.origin_type'));
    }
}
