<?php

namespace App\Http\Controllers\Api\Research;

use App\Http\Controllers\Api\Research\Concerns\AuthorizesContentChannel;
use App\Http\Controllers\Controller;
use App\Http\Resources\Research\ContentIdeaResource;
use App\Models\ContentChannel;
use App\Models\ContentIdea;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ContentIdeaController extends Controller
{
    use AuthorizesContentChannel;

    public function index(Request $request)
    {
        $ideas = ContentIdea::query()
            ->where('user_id', $request->user()->id)
            ->with(['channel:id,name,platform_id,niche', 'channel.platform:id,key,name'])
            ->withCount('sources')
            ->when($request->filled('content_channel_id'), fn ($query) => $query->where('content_channel_id', $request->integer('content_channel_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('research_date'), fn ($query) => $query->whereDate('research_date', $request->date('research_date')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('research_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('research_date', '<=', $request->date('date_to')))
            ->when($request->filled('min_priority'), fn ($query) => $query->where('priority_score', '>=', $request->integer('min_priority')))
            ->when($request->filled('suggested_content_type'), fn ($query) => $query->where('suggested_content_type', $request->string('suggested_content_type')))
            // Filtering by platform or research source means reaching through relations;
            // both are exposed because the dashboard spec lists them as filters.
            ->when($request->filled('platform_id'), fn ($query) => $query->whereHas('channel', fn ($q) => $q->where('platform_id', $request->integer('platform_id'))))
            ->when($request->filled('source_key'), fn ($query) => $query->whereHas('sources', fn ($q) => $q->where('source_key', $request->string('source_key'))))
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q').'%';
                $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('topic', 'like', $term));
            })
            // research_date first, always: each day's research run ADDS a new batch of
            // ideas rather than replacing the previous day's, and ordering by score
            // alone would let an old high-scoring idea bury every subsequent day's
            // results forever — indistinguishable from actually being overwritten.
            // Sorting by date first keeps every day visible while still ranking
            // within a day by whatever the caller asked for.
            ->orderByDesc('research_date')
            ->orderByDesc($this->sortColumn($request))
            ->orderByDesc('id')
            ->paginate(min(100, $request->integer('per_page', 20)))
            ->withQueryString();

        return ContentIdeaResource::collection($ideas);
    }

    public function forChannel(Request $request, ContentChannel $contentChannel)
    {
        $this->authorizeChannel($request, $contentChannel);

        $ideas = $contentChannel->contentIdeas()
            ->withCount('sources')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            // Same reasoning as index() above — newest research day first, so this
            // channel's "recent ideas" widget always shows today's batch on top of
            // (not instead of) every earlier day's.
            ->orderByDesc('research_date')
            ->orderByDesc('priority_score')
            ->orderByDesc('id')
            ->paginate(min(100, $request->integer('per_page', 20)));

        return ContentIdeaResource::collection($ideas);
    }

    public function show(Request $request, ContentIdea $contentIdea)
    {
        $this->authorizeIdea($request, $contentIdea);

        // The research evidence is the whole point of the detail view ("View
        // Research"), so it is always eager-loaded here.
        $contentIdea->load(['channel.platform', 'sources', 'researchRun']);

        return ContentIdeaResource::make($contentIdea);
    }

    public function update(Request $request, ContentIdea $contentIdea)
    {
        $this->authorizeIdea($request, $contentIdea);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(ContentIdea::STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'title' => ['sometimes', 'string', 'max:500'],
            'suggested_content_type' => ['sometimes', 'nullable', 'string', 'max:120'],
            'suggested_format' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        if (($data['status'] ?? null) === ContentIdea::STATUS_SELECTED && $contentIdea->selected_at === null) {
            $data['selected_at'] = now();
        }

        $contentIdea->update($data);

        return ContentIdeaResource::make($contentIdea->fresh(['channel.platform', 'sources']));
    }

    /**
     * Shortcut for the primary action in the UI. This phase deliberately stops at
     * "selected" — no script generation, no media, no publishing (spec section 24).
     */
    public function select(Request $request, ContentIdea $contentIdea)
    {
        $this->authorizeIdea($request, $contentIdea);

        $contentIdea->update([
            'status' => ContentIdea::STATUS_SELECTED,
            'selected_at' => $contentIdea->selected_at ?? now(),
        ]);

        return ContentIdeaResource::make($contentIdea->fresh(['channel.platform', 'sources']));
    }

    public function reject(Request $request, ContentIdea $contentIdea)
    {
        $this->authorizeIdea($request, $contentIdea);

        // Kept, not deleted: a rejected idea is exactly what the duplicate check needs
        // in order not to propose the same thing again tomorrow.
        $contentIdea->update(['status' => ContentIdea::STATUS_REJECTED]);

        return ContentIdeaResource::make($contentIdea->fresh(['channel.platform', 'sources']));
    }

    public function destroy(Request $request, ContentIdea $contentIdea)
    {
        $this->authorizeIdea($request, $contentIdea);

        $contentIdea->delete();

        return response()->noContent();
    }

    private function sortColumn(Request $request): string
    {
        // Allowlist, never the raw parameter — this value goes straight into ORDER BY.
        return match ((string) $request->string('sort')) {
            'trend' => 'trend_score',
            'relevance' => 'relevance_score',
            'freshness' => 'freshness_score',
            'created' => 'created_at',
            default => 'priority_score',
        };
    }

    private function authorizeIdea(Request $request, ContentIdea $idea): void
    {
        if ($idea->user_id !== $request->user()->id) {
            throw new NotFoundHttpException('Content idea not found.');
        }
    }
}
