<?php

namespace App\Services\AI;

use App\Models\Clip;
use App\Services\AI\Contracts\EmbeddingProvider;
use Illuminate\Support\Collection;

/**
 * Brute-force cosine-similarity search over Clip::embedding — not a real vector
 * index (no pgvector on this app's default sqlite connection), so this scans
 * every embedded clip in scope on every search. Fine at this app's scale; would
 * need a real vector store to stay fast past a few thousand clips per user.
 */
class ClipSearchService
{
    public function __construct(
        private readonly EmbeddingProvider $embeddings,
    ) {
    }

    /**
     * @return Collection<int, Clip>
     */
    public function search(int $userId, bool $isAdmin, string $query, ?int $projectId = null, int $limit = 24): Collection
    {
        $queryVector = $this->embeddings->embed($query);
        // Only compare against vectors produced by the currently-configured
        // embedding model — a vector from a since-changed provider/model has no
        // guaranteed dimensionality or semantic space in common with $queryVector.
        $currentModel = config('services.ai.embedding_provider') === 'nine_router'
            ? config('services.nine_router.embedding_model')
            : config('services.ai.embedding_provider', 'mock');

        $clips = $isAdmin
            ? Clip::query()
            : Clip::whereHas('project', fn ($q) => $q->where('user_id', $userId));

        if ($projectId) {
            $clips->where('project_id', $projectId);
        }

        return $clips->whereNotNull('embedding')
            ->where('embedding_model', $currentModel)
            ->get()
            ->map(fn (Clip $clip) => [
                'clip' => $clip,
                'score' => $this->cosineSimilarity($queryVector, $clip->embedding ?? []),
            ])
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('clip')
            ->values();
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) {
            return -1.0;
        }

        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        foreach ($a as $i => $valueA) {
            $valueB = $b[$i];
            $dot += $valueA * $valueB;
            $magA += $valueA ** 2;
            $magB += $valueB ** 2;
        }

        if ($magA === 0.0 || $magB === 0.0) {
            return -1.0;
        }

        return $dot / (sqrt($magA) * sqrt($magB));
    }
}
