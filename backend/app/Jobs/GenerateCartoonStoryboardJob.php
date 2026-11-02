<?php

namespace App\Jobs;

use App\Models\CartoonScene;
use App\Models\CartoonVideo;
use App\Models\ContentBrief;
use App\Models\ContentIdea;
use App\Services\AI\Contracts\StoryboardProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Step 1 of a cartoon video: read the research (a ContentBrief's script or a
 * ContentIdea's summary), have the StoryboardProvider condense it into short-form
 * scenes, and store them for the user to review. Nothing paid beyond the one chat
 * call happens here — images/audio/video are only generated after the user approves
 * the storyboard (RenderCartoonSceneJob), so a bad storyboard costs almost nothing.
 */
class GenerateCartoonStoryboardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $cartoonVideoId)
    {
    }

    public function handle(StoryboardProvider $storyboard): void
    {
        $video = CartoonVideo::findOrFail($this->cartoonVideoId);

        $video->update([
            'status' => CartoonVideo::STATUS_STORYBOARDING,
            'progress' => 5,
            'message' => 'Menyusun storyboard...',
            'failure_reason' => null,
            'started_at' => now(),
        ]);

        try {
            [$topic, $material] = $this->loadSource($video);

            $result = $storyboard->generateStoryboard($topic, $material, (int) config('cartoon.max_scenes', 10));

            DB::transaction(function () use ($video, $result) {
                $video->scenes()->delete();

                foreach ($result->scenes as $i => $scene) {
                    CartoonScene::create([
                        'cartoon_video_id' => $video->id,
                        'position' => $i + 1,
                        'narration' => $scene['narration'],
                        'visual_prompt' => $scene['visual_prompt'],
                        'motion_prompt' => $scene['motion_prompt'] ?? null,
                    ]);
                }

                $video->update([
                    'character_description' => $result->character_description ?: null,
                    'status' => CartoonVideo::STATUS_STORYBOARD_READY,
                    'progress' => 10,
                    'message' => 'Storyboard siap — periksa lalu render.',
                ]);
            });
        } catch (Throwable $e) {
            $video->update([
                'status' => CartoonVideo::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string} [topic, material]
     */
    private function loadSource(CartoonVideo $video): array
    {
        if ($video->source_type === CartoonVideo::SOURCE_CONTENT_BRIEF) {
            $brief = ContentBrief::findOrFail($video->source_id);

            $material = $brief->narrative_full_script
                ?: collect($brief->narrative_sections ?? [])->pluck('narration_text')->implode("\n\n");

            if (trim((string) $material) === '') {
                throw new RuntimeException('Content brief ini belum punya naskah. Tunggu riset selesai atau buat ulang naskahnya.');
            }

            return [$brief->narrative_title ?: $brief->topic, (string) $material];
        }

        $idea = ContentIdea::findOrFail($video->source_id);

        $material = collect([
            "Judul: {$idea->title}",
            $idea->short_description,
            $idea->content_angle ? "Sudut pandang: {$idea->content_angle}" : null,
            $idea->why_this_topic ? "Kenapa topik ini: {$idea->why_this_topic}" : null,
            $idea->source_summary ? "Ringkasan sumber: {$idea->source_summary}" : null,
            $idea->keywords ? 'Kata kunci: ' . implode(', ', (array) $idea->keywords) : null,
        ])->filter()->implode("\n\n");

        return [$idea->topic ?: $idea->title, $material];
    }
}
