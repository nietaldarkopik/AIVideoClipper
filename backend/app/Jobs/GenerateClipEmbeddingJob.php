<?php

namespace App\Jobs;

use App\Models\Clip;
use App\Services\AI\Contracts\EmbeddingProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Embeds a clip's title/caption/hashtags/hook/reaction-script into a vector for
 * ClipSearchService — dispatched right after a clip is created
 * (ClipGenerationService::selectAndCreateClips) and again whenever its
 * title/caption/hashtags change (ClipController::update). Never fails the caller:
 * a clip without an embedding just doesn't show up in search results, same
 * resilience pattern as ClipGenerationService::attachReactionScript().
 */
class GenerateClipEmbeddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(public int $clipId)
    {
    }

    public function handle(EmbeddingProvider $embeddings): void
    {
        $clip = Clip::with('clipCandidate')->find($this->clipId);
        if (! $clip) {
            return;
        }

        $text = $this->buildText($clip);
        if (trim($text) === '') {
            return;
        }

        try {
            $vector = $embeddings->embed($text);

            $clip->update([
                'embedding' => $vector,
                'embedding_model' => config('services.ai.embedding_provider') === 'nine_router'
                    ? config('services.nine_router.embedding_model')
                    : config('services.ai.embedding_provider', 'mock'),
            ]);
        } catch (Throwable $e) {
            Log::warning('Clip embedding generation failed, clip will not appear in search', [
                'clip_id' => $clip->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildText(Clip $clip): string
    {
        $parts = [
            $clip->title,
            $clip->caption,
            implode(' ', $clip->hashtags ?? []),
            $clip->clipCandidate?->hook_text,
            $clip->reaction_script,
        ];

        return implode("\n", array_filter($parts, fn ($p) => filled($p)));
    }
}
