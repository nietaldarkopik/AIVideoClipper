<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVideoJob;
use App\Models\ClipCandidate;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\Contracts\ContentAnalysisProvider;
use App\Services\AI\DTOs\ClipCandidateData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AnalyzeVideoJob's self-consistency check — drop a candidate whose hook_text
 * doesn't actually appear in the transcript at its claimed timestamp (a real
 * failure mode: a model describing one moment while attaching a different
 * moment's timestamp to it, see the check's own docblock) — called
 * `Log::warning(...)` with no `use Illuminate\Support\Facades\Log;` anywhere in
 * the file. Every real analysis run whose provider ever produced one mismatched
 * candidate would crash with "Class App\Jobs\Log not found" instead of dropping
 * it and continuing, turning a defensive check into an outage. The mock
 * provider always builds hook_text directly from the matching transcript
 * segment, so this path was never exercised by the existing analyze tests —
 * this one supplies a provider that deliberately mismatches to reach it.
 */
class AnalyzeVideoJobHookMismatchTest extends TestCase
{
    use RefreshDatabase;

    private const SRT = <<<SRT
        1
        00:00:00,000 --> 00:00:10,000
        This is the first line of the video.

        2
        00:00:10,000 --> 00:00:20,000
        And here is a second, punchier moment worth clipping.
        SRT;

    public function test_a_candidate_with_mismatched_hook_text_is_dropped_without_crashing(): void
    {
        Queue::fake();
        Storage::fake('media');
        Storage::disk('media')->put('videos/1/captions.en.srt', self::SRT);

        $user = User::factory()->create();
        $project = $user->projects()->create([
            'title' => 'Mismatch test', 'status' => Project::STATUS_DRAFT, 'last_edited_at' => now(),
        ]);
        $video = $project->videos()->create([
            'source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 20,
            'disk_path' => 'videos/1/source.mp4',
            'metadata' => ['captions_srt_path' => 'videos/1/captions.en.srt', 'captions_language' => 'en'],
        ]);

        $this->app->bind(ContentAnalysisProvider::class, fn () => new class implements ContentAnalysisProvider
        {
            public function detectScenes(string $videoPath, float $durationSeconds): array
            {
                return [];
            }

            public function analyzeMoments($transcript, array $scenes, float $durationSeconds, ?\Closure $shouldAbort = null): array
            {
                return [
                    // hook_text describes words that never appear anywhere in the
                    // transcript at all, let alone at 0-10s — exactly the
                    // "plausible-looking but wrong" mismatch the check exists for.
                    new ClipCandidateData(
                        startTime: 0.0,
                        endTime: 10.0,
                        overallScore: 80, engagementScore: 80, hookScore: 80, storyScore: 80,
                        emotionalScore: 80, informationScore: 80, viralPotential: 80,
                        hookText: 'completely unrelated dragons and spaceships nonsense',
                        momentType: 'hook',
                        reasons: [], explanation: '', suggestedTitle: 'Title', suggestedCaption: 'Caption',
                        hashtags: [],
                    ),
                ];
            }
        });

        // The real bug: this call used to throw "Class App\Jobs\Log not found"
        // instead of completing. If Log:: is ever unimported again, this
        // reproduces the exact crash.
        app()->call([new AnalyzeVideoJob($project->id, $video->id, autoGenerateClips: false), 'handle']);

        $this->assertSame(0, ClipCandidate::where('project_id', $project->id)->count());
        $this->assertSame(Project::STATUS_COMPLETED, $project->fresh()->status);
    }
}
