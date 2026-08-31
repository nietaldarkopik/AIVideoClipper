<?php

namespace App\Jobs;

use App\Exceptions\JobCancelledException;
use App\Models\ContentBrief;
use App\Services\AI\Contracts\VideoNarrativeProvider;
use App\Services\AI\Contracts\WebFetchProvider;
use App\Services\AI\Contracts\WebSearchProvider;
use App\Services\AI\DTOs\WebSearchResult;
use App\Services\ContentResearch\RelevantVideoFinder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs the full trending-topic -> video-script research pipeline for one
 * ContentBrief: web search -> fetch each source's content -> generate an
 * original Indonesian long-form narrative -> find relevant candidate videos.
 *
 * Not wired into the polymorphic ProcessingJob table (this is a standalone
 * entity, not tied to a project/video/clip) — cancellation follows the
 * VideoBatch/ProcessBatchItemJob precedent instead: a cooperative
 * `cancel_requested` boolean column checked between phases.
 *
 * $scriptOnly reruns just the narrative-generation phase against the brief's
 * already-stored sources, leaving sources/candidate_videos untouched — this is
 * what powers the "regenerate script" action.
 */
class GenerateContentBriefJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(
        public int $contentBriefId,
        public bool $scriptOnly = false,
    ) {
    }

    public function handle(
        WebSearchProvider $webSearch,
        WebFetchProvider $webFetch,
        VideoNarrativeProvider $narrativeProvider,
        RelevantVideoFinder $videoFinder,
    ): void {
        $brief = ContentBrief::findOrFail($this->contentBriefId);

        $brief->update(['started_at' => now(), 'message' => 'Starting...']);

        try {
            $sources = $this->scriptOnly ? ($brief->sources ?? []) : $this->searchAndFetchSources($brief, $webSearch, $webFetch);

            $this->abortIfCancelled($brief);
            $brief->update(['status' => ContentBrief::STATUS_GENERATING_SCRIPT, 'progress' => 80, 'message' => 'Menulis naskah...']);

            $sourcesForPrompt = array_map(fn (array $s) => [
                'title' => $s['title'] ?? '',
                'url' => $s['url'] ?? '',
                'content' => $s['content_excerpt'] ?? '',
            ], $sources);

            $narrative = $narrativeProvider->generateNarrative($brief->topic, $sourcesForPrompt);

            $brief->update([
                'narrative_title' => $narrative->title,
                'narrative_hook' => $narrative->hook,
                'narrative_sections' => array_map(fn ($s) => [
                    'heading' => $s->heading,
                    'narration_text' => $s->narration_text,
                    'duration_estimate_seconds' => $s->duration_estimate_seconds,
                ], $narrative->sections),
                'narrative_full_script' => $narrative->full_script,
                'narrative_suggested_description' => $narrative->suggested_description,
                'narrative_suggested_hashtags' => $narrative->suggested_hashtags,
            ]);

            if (! $this->scriptOnly) {
                $this->abortIfCancelled($brief);
                $brief->update(['status' => ContentBrief::STATUS_FINDING_VIDEOS, 'progress' => 90, 'message' => 'Mencari video terkait...']);

                $candidateVideos = $videoFinder->find($brief->topic);
                $brief->update(['candidate_videos' => $candidateVideos]);
            }

            $brief->update([
                'status' => ContentBrief::STATUS_COMPLETED,
                'progress' => 100,
                'message' => 'Selesai',
                'finished_at' => now(),
            ]);
        } catch (JobCancelledException $e) {
            $brief->update([
                'status' => ContentBrief::STATUS_CANCELLED,
                'message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $brief->update([
                'status' => ContentBrief::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }
    }

    /**
     * @return array<int, array{title: string, url: string, snippet: ?string, published_at: ?string, content_excerpt: ?string}>
     */
    private function searchAndFetchSources(ContentBrief $brief, WebSearchProvider $webSearch, WebFetchProvider $webFetch): array
    {
        $this->abortIfCancelled($brief);
        $brief->update(['status' => ContentBrief::STATUS_SEARCHING, 'progress' => 10, 'message' => 'Mencari informasi...']);

        $results = $webSearch->search($brief->topic, maxResults: 8);

        $this->abortIfCancelled($brief);
        $brief->update(['status' => ContentBrief::STATUS_FETCHING_SOURCES, 'progress' => 20, 'message' => 'Mengambil isi sumber...']);

        $sources = [];
        $total = count($results);

        foreach ($results as $i => $result) {
            /** @var WebSearchResult $result */
            $this->abortIfCancelled($brief);

            $contentExcerpt = null;
            if ($result->url === '') {
                // No real page to crawl (e.g. NineRouterWebSearchProvider's
                // synthesized-answer fallback when a query returns zero citable
                // results) — the snippet already IS the full content.
                $contentExcerpt = $result->snippet !== null ? mb_substr($result->snippet, 0, 8000) : null;
            } else {
                try {
                    $contentExcerpt = mb_substr($webFetch->fetch($result->url), 0, 8000);
                } catch (Throwable) {
                    // One source failing to fetch shouldn't sink the whole brief —
                    // it's simply omitted from the prompt context below.
                }
            }

            $sources[] = [
                'title' => $result->title,
                'url' => $result->url,
                'snippet' => $result->snippet,
                'published_at' => $result->published_at,
                'content_excerpt' => $contentExcerpt,
            ];

            $progress = 20 + (int) round(60 * ($i + 1) / max($total, 1));
            $brief->update(['progress' => $progress]);
        }

        $brief->update(['sources' => $sources]);

        return $sources;
    }

    private function abortIfCancelled(ContentBrief $brief): void
    {
        if (ContentBrief::whereKey($brief->id)->value('cancel_requested')) {
            throw new JobCancelledException('Dibatalkan oleh pengguna.');
        }
    }
}
