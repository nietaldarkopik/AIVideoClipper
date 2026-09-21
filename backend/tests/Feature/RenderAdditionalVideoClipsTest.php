<?php

namespace Tests\Feature;

use App\Jobs\RenderClipJob;
use App\Models\Clip;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\Contracts\ReframingProvider;
use App\Services\Video\FFmpegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * RenderClipJob::renderAdditionalVideoClips() — independently rendering each
 * of a clip's "additional video clips" (each cut from a DIFFERENT Video row)
 * before FFmpegService::concatSegments() joins them onto the main clip's own
 * output. Driven via reflection (the method is private, matching every other
 * RenderClipJob helper — composeIntroOutro(), resolveSegments() — none of
 * which are exposed just for testing) against Storage::fake('media'), the
 * same safe, isolated pattern MediaUploadTest already established for tests
 * that need REAL ffmpeg I/O against a REAL (but sandboxed) disk path, never
 * the actual dev media directory or database.
 *
 * config('services.ai.reframing_provider') is forced to 'mock' here — the
 * dev .env's real AI_REFRAMING_PROVIDER=face_tracker would otherwise try to
 * run face detection against a synthetic solid-color test video, exactly the
 * reason every other test in this suite that touches AI providers relies on
 * the mock default instead.
 */
class RenderAdditionalVideoClipsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        config(['services.ai.reframing_provider' => 'mock']);
    }

    private function makeVideoFile(string $relative, string $color, float $duration): void
    {
        $abs = Storage::disk('media')->path($relative);
        @mkdir(dirname($abs), 0777, true);
        $cmd = [
            'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', "color=c={$color}:s=64x64:rate=10:d={$duration}",
            '-f', 'lavfi', '-i', 'sine=frequency=300:duration='.$duration,
            '-c:v', 'libx264', '-preset', 'ultrafast', '-c:a', 'aac', $abs,
        ];
        exec(implode(' ', array_map('escapeshellarg', $cmd)), $output, $exitCode);
        if ($exitCode !== 0) {
            $this->markTestSkipped('ffmpeg is not available or failed to build a test fixture: '.implode("\n", $output));
        }
    }

    /**
     * @return array{path: string, transition_in: ?array}[]
     */
    private function invoke(Clip $clip): array
    {
        $method = new ReflectionMethod(RenderClipJob::class, 'renderAdditionalVideoClips');
        $method->setAccessible(true);

        return $method->invoke(
            new RenderClipJob($clip->id),
            $clip,
            app(FFmpegService::class),
            app(ReframingProvider::class),
            Storage::disk('media'),
            64,
            64,
            null,
            0.8,
        );
    }

    public function test_renders_and_pairs_each_entry_with_its_own_transition(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);

        $this->makeVideoFile('videos/main.mp4', 'blue', 3);
        $mainVideo = $project->videos()->create([
            'source_type' => 'youtube', 'status' => 'ready', 'disk_path' => 'videos/main.mp4',
            'duration_seconds' => 3, 'width' => 64, 'height' => 64,
        ]);

        $this->makeVideoFile('videos/extra.mp4', 'red', 3);
        $extraVideo = $project->videos()->create([
            'source_type' => 'youtube', 'status' => 'ready', 'disk_path' => 'videos/extra.mp4',
            'duration_seconds' => 3, 'width' => 64, 'height' => 64,
        ]);

        $clip = Clip::create([
            'project_id' => $project->id, 'video_id' => $mainVideo->id,
            'start_time' => 0, 'end_time' => 3, 'duration' => 3,
            'aspect_ratio' => '16:9', 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => Clip::STATUS_QUEUED,
            'additional_video_clips' => [
                ['video_id' => $extraVideo->id, 'start' => 0, 'end' => 2, 'transition_in' => ['type' => 'fade', 'duration' => 0.5]],
            ],
        ]);

        $result = $this->invoke($clip);

        $this->assertCount(1, $result);
        $this->assertSame('fade', $result[0]['transition_in']['type']);
        Storage::disk('media')->assertExists($result[0]['path']);

        $probe = app(FFmpegService::class)->probe(Storage::disk('media')->path($result[0]['path']));
        $this->assertEqualsWithDelta(2.0, $probe['duration'], 0.3);
        $this->assertSame(64, $probe['width']);
        $this->assertSame(64, $probe['height']);
    }

    public function test_skips_an_entry_whose_video_was_deleted_rather_than_failing(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $this->makeVideoFile('videos/main.mp4', 'blue', 3);
        $mainVideo = $project->videos()->create([
            'source_type' => 'youtube', 'status' => 'ready', 'disk_path' => 'videos/main.mp4',
            'duration_seconds' => 3, 'width' => 64, 'height' => 64,
        ]);

        $clip = Clip::create([
            'project_id' => $project->id, 'video_id' => $mainVideo->id,
            'start_time' => 0, 'end_time' => 3, 'duration' => 3,
            'aspect_ratio' => '16:9', 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => Clip::STATUS_QUEUED,
            'additional_video_clips' => [
                ['video_id' => 999999, 'start' => 0, 'end' => 2],
            ],
        ]);

        $this->assertSame([], $this->invoke($clip));
    }

    public function test_skips_an_entry_with_a_degenerate_time_range(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $this->makeVideoFile('videos/main.mp4', 'blue', 3);
        $mainVideo = $project->videos()->create([
            'source_type' => 'youtube', 'status' => 'ready', 'disk_path' => 'videos/main.mp4',
            'duration_seconds' => 3, 'width' => 64, 'height' => 64,
        ]);

        $clip = Clip::create([
            'project_id' => $project->id, 'video_id' => $mainVideo->id,
            'start_time' => 0, 'end_time' => 3, 'duration' => 3,
            'aspect_ratio' => '16:9', 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => Clip::STATUS_QUEUED,
            'additional_video_clips' => [
                ['video_id' => $mainVideo->id, 'start' => 2, 'end' => 2],
            ],
        ]);

        $this->assertSame([], $this->invoke($clip));
    }

    public function test_empty_additional_video_clips_returns_immediately(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['title' => 'P', 'status' => Project::STATUS_COMPLETED, 'last_edited_at' => now()]);
        $video = $project->videos()->create(['source_type' => 'youtube', 'status' => 'ready', 'duration_seconds' => 3]);

        $clip = Clip::create([
            'project_id' => $project->id, 'video_id' => $video->id,
            'start_time' => 0, 'end_time' => 3, 'duration' => 3,
            'aspect_ratio' => '16:9', 'subtitle_language' => 'en',
            'title' => 'Clip', 'status' => Clip::STATUS_QUEUED,
        ]);

        $this->assertSame([], $this->invoke($clip));
    }
}
