<?php

namespace Tests\Feature;

use App\Models\Clip;
use App\Models\Project;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\Video\DefaultTemplateConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers Clip::targetResolution() — a template's own resolution_width/height
 * (set via the Template Builder's Canvas Size UI) must win over the clip's own
 * aspect_ratio bucket, since that's the whole point of letting a template use
 * a custom canvas size.
 */
class ClipTargetResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function clip(User $user, array $overrides = []): Clip
    {
        $project = $user->projects()->create(['title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 20]);

        return Clip::create(array_merge([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => Clip::STATUS_COMPLETED,
        ], $overrides));
    }

    public function test_falls_back_to_aspect_ratio_bucket_when_no_template_is_attached(): void
    {
        $user = User::factory()->create();
        $clip = $this->clip($user, ['aspect_ratio' => '16:9']);

        $this->assertSame([1920, 1080], $clip->targetResolution());
    }

    public function test_falls_back_when_the_attached_template_has_no_custom_resolution(): void
    {
        $user = User::factory()->create();
        $template = Template::create([
            'name' => 'No custom size', 'slug' => 'no-custom-size', 'aspect_ratio' => '1:1',
            'resolution_width' => 0, 'resolution_height' => 0, 'status' => 'published',
        ]);
        $clip = $this->clip($user, ['aspect_ratio' => '1:1', 'template_id' => $template->id]);

        $this->assertSame([1080, 1080], $clip->targetResolution(), "the clip's own aspect_ratio bucket wins when the template's resolution is unset/zero");
    }

    public function test_a_templates_custom_resolution_overrides_the_clips_own_aspect_ratio_bucket(): void
    {
        $user = User::factory()->create();
        $template = Template::create([
            'name' => 'Custom Canvas', 'slug' => 'custom-canvas', 'aspect_ratio' => '9:16',
            'resolution_width' => 1600, 'resolution_height' => 900, 'status' => 'published',
        ]);
        $version = TemplateVersion::create([
            'template_id' => $template->id, 'version_number' => 1, 'label' => 'v1',
            'is_published' => true, 'config' => DefaultTemplateConfig::config(),
        ]);
        $template->update(['current_version_id' => $version->id]);

        // Clip's OWN aspect_ratio still says 9:16 (1080x1920) — the template's
        // 1600x900 canvas must win anyway.
        $clip = $this->clip($user, ['aspect_ratio' => '9:16', 'template_id' => $template->id, 'template_version_id' => $version->id]);

        $this->assertSame([1600, 900], $clip->targetResolution());
    }
}
