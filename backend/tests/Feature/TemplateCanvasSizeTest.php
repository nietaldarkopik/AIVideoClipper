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
 * Covers the Template Builder's "Canvas Size" feature end to end: an admin can
 * set a template's exact resolution (preset or custom) via the API, and a clip
 * using that template picks it up as its real render size.
 */
class TemplateCanvasSizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_accepts_a_custom_resolution_override(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->postJson('/api/admin/templates', [
            'name' => 'Custom Canvas',
            'aspect_ratio' => '16:9',
            'resolution_width' => 1600,
            'resolution_height' => 900,
        ]);

        $response->assertCreated();
        $this->assertSame(1600, $response->json('data.resolution.width'));
        $this->assertSame(900, $response->json('data.resolution.height'));
    }

    public function test_update_can_change_an_existing_templates_canvas_size(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $template = Template::create([
            'name' => 'T', 'slug' => 't', 'aspect_ratio' => '9:16',
            'resolution_width' => 1080, 'resolution_height' => 1920, 'status' => 'published',
        ]);

        $response = $this->actingAs($admin)->patchJson("/api/admin/templates/{$template->id}", [
            'resolution_width' => 1440,
            'resolution_height' => 1080,
            'aspect_ratio' => '16:9',
        ]);

        $response->assertOk();
        $template->refresh();
        $this->assertSame(1440, $template->resolution_width);
        $this->assertSame(1080, $template->resolution_height);
        $this->assertSame('16:9', $template->aspect_ratio);
    }

    public function test_update_rejects_a_resolution_outside_sane_bounds(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $template = Template::create([
            'name' => 'T', 'slug' => 't2', 'aspect_ratio' => '9:16',
            'resolution_width' => 1080, 'resolution_height' => 1920, 'status' => 'published',
        ]);

        $response = $this->actingAs($admin)->patchJson("/api/admin/templates/{$template->id}", [
            'resolution_width' => 50,
        ]);

        $response->assertStatus(422);
    }

    public function test_a_clip_using_the_template_renders_at_its_custom_canvas_size(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $template = Template::create([
            'name' => 'Custom Canvas', 'slug' => 'custom-canvas-2', 'aspect_ratio' => '9:16',
            'resolution_width' => 1080, 'resolution_height' => 1920, 'status' => 'published',
        ]);
        $version = TemplateVersion::create([
            'template_id' => $template->id, 'version_number' => 1, 'label' => 'v1',
            'is_published' => true, 'config' => DefaultTemplateConfig::config(),
        ]);
        $template->update(['current_version_id' => $version->id]);

        $this->actingAs($admin)->patchJson("/api/admin/templates/{$template->id}", [
            'resolution_width' => 1600,
            'resolution_height' => 900,
            'aspect_ratio' => '16:9',
        ])->assertOk();

        $user = User::factory()->create();
        $project = $user->projects()->create(['title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 20]);
        $clip = Clip::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 20, 'duration' => 20,
            'aspect_ratio' => '9:16', 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => Clip::STATUS_COMPLETED,
            'template_id' => $template->id, 'template_version_id' => $version->id,
        ]);

        $this->assertSame([1600, 900], $clip->targetResolution());
    }
}
