<?php

namespace Tests\Feature;

use App\Models\Clip;
use App\Models\CoverTemplate;
use App\Models\Project;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Video\CoverGeneratorService;
use App\Services\Video\DefaultCoverTemplateConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every rendered clip now gets a cover (see RenderClipJob), so picking the
 * template can't depend on anyone having configured one. These lock the
 * priority order in CoverGeneratorService::resolveTemplate().
 */
class CoverTemplateResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function template(string $name, string $aspect = '9:16', string $status = 'published'): CoverTemplate
    {
        return CoverTemplate::create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'aspect_ratio' => $aspect,
            'config' => DefaultCoverTemplateConfig::config(),
            'status' => $status,
        ]);
    }

    private function clip(User $user, string $aspect = '9:16'): Clip
    {
        $project = $user->projects()->create([
            'title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now(),
        ]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 20]);

        return Clip::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => $aspect, 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => Clip::STATUS_COMPLETED, 'output_path' => 'clips/x.mp4',
        ]);
    }

    private function resolver(): CoverGeneratorService
    {
        return app(CoverGeneratorService::class);
    }

    public function test_a_template_already_on_the_clip_wins(): void
    {
        $user = User::factory()->create();
        $chosen = $this->template('Chosen');
        $this->template('Other');

        $clip = $this->clip($user);
        $clip->update(['cover_template_id' => $chosen->id]);

        // Re-resolving must keep returning it, so re-rendering a clip never
        // silently restyles a cover someone already approved.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($chosen->id, $this->resolver()->resolveTemplate($clip)->id);
        }
    }

    public function test_the_destination_channels_default_wins_when_the_clip_has_none(): void
    {
        $user = User::factory()->create();
        $accountDefault = $this->template('Account Default');
        $this->template('Unrelated');

        $account = $user->socialAccounts()->create([
            'platform' => 'tiktok', 'account_name' => 'acc', 'status' => SocialAccount::STATUS_CONNECTED,
            'default_cover_template_id' => $accountDefault->id,
        ]);

        $resolved = $this->resolver()->resolveTemplate($this->clip($user), $account);

        $this->assertSame($accountDefault->id, $resolved->id);
    }

    public function test_it_falls_back_to_a_template_configured_on_the_owners_channels(): void
    {
        $user = User::factory()->create();
        $configured = $this->template('Configured Somewhere');
        $this->template('Never Configured');

        $user->socialAccounts()->create([
            'platform' => 'tiktok', 'account_name' => 'acc', 'status' => SocialAccount::STATUS_CONNECTED,
            'default_cover_template_id' => $configured->id,
        ]);

        // No account passed: render time doesn't know the destination yet, but a
        // template the user deliberately configured still beats an unrelated one.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($configured->id, $this->resolver()->resolveTemplate($this->clip($user))->id);
        }
    }

    public function test_it_picks_a_random_published_template_matching_the_clips_aspect_ratio(): void
    {
        $user = User::factory()->create();
        $vertical = $this->template('Vertical A');
        $verticalB = $this->template('Vertical B');
        $landscape = $this->template('Landscape', '16:9');

        $clip = $this->clip($user, '9:16');
        $picked = [];
        for ($i = 0; $i < 20; $i++) {
            $picked[] = $this->resolver()->resolveTemplate($clip)->id;
        }

        $this->assertNotContains($landscape->id, $picked, 'A 16:9 cover was picked for a 9:16 clip.');
        $this->assertEqualsCanonicalizing([$vertical->id, $verticalB->id], array_unique($picked));
    }

    public function test_it_still_finds_a_template_when_none_matches_the_aspect_ratio(): void
    {
        $user = User::factory()->create();
        $landscape = $this->template('Landscape Only', '16:9');

        $resolved = $this->resolver()->resolveTemplate($this->clip($user, '9:16'));

        $this->assertSame($landscape->id, $resolved->id);
    }

    public function test_it_ignores_unpublished_templates_and_returns_null_when_there_are_none(): void
    {
        $user = User::factory()->create();
        $this->template('Draft', '9:16', 'draft');
        $this->template('Archived', '9:16', 'archived');

        $this->assertNull($this->resolver()->resolveTemplate($this->clip($user)));
    }
}
