<?php

namespace Tests\Feature;

use App\Models\Clip;
use App\Models\ClipCandidate;
use App\Models\CoverTemplate;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use App\Services\AI\DTOs\ClipCandidateData;
use App\Services\Video\ClipGenerationService;
use App\Services\Video\DefaultCoverTemplateConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Covers the "AI writes thumbnail text while it finds the moment" path: the
 * analysis produces short cover headline/label variants, creating a clip seeds
 * its cover fields from them, and a cover template's own placeholder text is
 * replaced by the clip's — but only for the elements that template's design
 * actually has.
 */
class CoverTextGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_trims_dedupes_and_drops_overlong_variants(): void
    {
        $titles = ClipCandidateData::normalizeCoverStrings([
            '  Negara   Cuma Seremonial?  ',
            'Negara Cuma Seremonial?',           // duplicate of the trimmed one above
            'Ini Yang Bikin Kaget',
            'Judul yang jauh terlalu panjang sekali untuk sebuah cover thumbnail', // > 42 chars
            123,                                  // not a string
            '',
        ], 42);

        $this->assertSame(['Negara Cuma Seremonial?', 'Ini Yang Bikin Kaget'], $titles);
    }

    public function test_normalize_caps_how_many_variants_are_kept(): void
    {
        $subtitles = ClipCandidateData::normalizeCoverStrings(['SATU', 'DUA', 'TIGA', 'EMPAT'], 18);

        $this->assertCount(3, $subtitles);
    }

    public function test_candidate_cover_texts_seed_a_new_clips_cover_fields(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $project = $user->projects()->create([
            'title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now(),
        ]);
        $video = Video::create([
            'project_id' => $project->id, 'source_type' => 'upload', 'disk_path' => 'videos/1/source.mp4',
            'duration_seconds' => 120, 'width' => 1920, 'height' => 1080, 'status' => 'ready',
        ]);

        ClipCandidate::create([
            'project_id' => $project->id,
            'video_id' => $video->id,
            'start_time' => 5, 'end_time' => 35, 'duration' => 30,
            'overall_score' => 90, 'engagement_score' => 90, 'hook_score' => 90,
            'story_score' => 90, 'emotional_score' => 90, 'information_score' => 90,
            'viral_potential' => 90, 'hook_text' => 'hook', 'moment_type' => 'hook',
            'reasons' => [], 'explanation' => 'x',
            'suggested_title' => 'A caption-length title that is far too long for a thumbnail',
            'suggested_caption' => 'caption',
            'suggested_hashtags' => [],
            'cover_titles' => ['Negara Cuma Seremonial?', 'Ini Yang Bikin Kaget'],
            'cover_subtitles' => ['FAKTA BARU', 'VIRAL'],
            'status' => 'pending',
        ]);

        $clips = app(ClipGenerationService::class)->selectAndCreateClips($project, ['mode' => 'top_3']);
        $clip = $clips->first();

        $this->assertSame('Negara Cuma Seremonial?', $clip->cover_text);
        $this->assertSame('FAKTA BARU', $clip->cover_kicker);
        $this->assertSame('VIRAL', $clip->cover_subline);
    }

    public function test_a_clip_without_ai_cover_texts_falls_back_to_the_suggested_title(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $project = $user->projects()->create([
            'title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now(),
        ]);
        $video = Video::create([
            'project_id' => $project->id, 'source_type' => 'upload', 'disk_path' => 'videos/1/source.mp4',
            'duration_seconds' => 120, 'width' => 1920, 'height' => 1080, 'status' => 'ready',
        ]);

        ClipCandidate::create([
            'project_id' => $project->id,
            'video_id' => $video->id,
            'start_time' => 5, 'end_time' => 35, 'duration' => 30,
            'overall_score' => 90, 'engagement_score' => 90, 'hook_score' => 90,
            'story_score' => 90, 'emotional_score' => 90, 'information_score' => 90,
            'viral_potential' => 90, 'hook_text' => 'hook', 'moment_type' => 'hook',
            'reasons' => [], 'explanation' => 'x',
            'suggested_title' => 'Legacy Title',
            'suggested_caption' => 'caption',
            'suggested_hashtags' => [],
            'status' => 'pending',
        ]);

        $clip = app(ClipGenerationService::class)->selectAndCreateClips($project, ['mode' => 'top_3'])->first();

        $this->assertSame('Legacy Title', $clip->cover_text);
        $this->assertNull($clip->cover_kicker);
    }

    public function test_generate_cover_endpoint_persists_the_clips_own_labels(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create([
            'title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now(),
        ]);
        $video = Video::create([
            'project_id' => $project->id, 'source_type' => 'upload', 'disk_path' => 'videos/1/source.mp4',
            'duration_seconds' => 120, 'width' => 1920, 'height' => 1080, 'status' => 'ready',
        ]);
        $clip = Clip::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 10, 'duration' => 10, 'aspect_ratio' => '9:16',
            'status' => Clip::STATUS_COMPLETED, 'output_path' => null,
        ]);
        $coverTemplate = CoverTemplate::create([
            'name' => 'CT', 'slug' => 'ct', 'aspect_ratio' => '9:16',
            'config' => DefaultCoverTemplateConfig::config(), 'status' => 'published',
        ]);

        // The render itself can't run here (no rendered clip file), but the
        // labels must still be recorded so a later Generate picks them up.
        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/generate-cover", [
            'cover_template_id' => $coverTemplate->id,
            'kicker' => 'FAKTA BARU',
            'subline' => 'TONTON SAMPAI HABIS',
        ])->assertStatus(422);

        $clip->refresh();
        $this->assertSame('FAKTA BARU', $clip->cover_kicker);
        $this->assertSame('TONTON SAMPAI HABIS', $clip->cover_subline);
    }
}
