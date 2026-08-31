<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContentBriefResource;
use App\Jobs\GenerateContentBriefJob;
use App\Models\ContentBrief;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ContentBriefController extends Controller
{
    public function index(Request $request)
    {
        $briefs = $request->user()->contentBriefs()
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ContentBriefResource::collection($briefs);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'max:2000'],
            'region_code' => ['nullable', 'string', 'max:10'],
            'source_platform' => ['nullable', 'string', 'max:50'],
            'source_trending_title' => ['nullable', 'string', 'max:500'],
            'source_trending_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $brief = $request->user()->contentBriefs()->create([
            'topic' => $data['topic'],
            'region_code' => $data['region_code'] ?? null,
            'source_platform' => $data['source_platform'] ?? null,
            'source_trending_title' => $data['source_trending_title'] ?? null,
            'source_trending_url' => $data['source_trending_url'] ?? null,
            'status' => ContentBrief::STATUS_PENDING,
        ]);

        GenerateContentBriefJob::dispatch($brief->id);

        return ContentBriefResource::make($brief)->response()->setStatusCode(201);
    }

    public function show(Request $request, ContentBrief $contentBrief)
    {
        $this->authorizeBrief($request, $contentBrief);

        return ContentBriefResource::make($contentBrief);
    }

    public function regenerateScript(Request $request, ContentBrief $contentBrief)
    {
        $this->authorizeBrief($request, $contentBrief);

        if (in_array($contentBrief->status, ContentBrief::ACTIVE_STATUSES, true)) {
            return response()->json(['message' => 'This content brief is still being generated.'], 422);
        }

        if (empty($contentBrief->sources)) {
            return response()->json(['message' => 'No sources to regenerate a script from yet.'], 422);
        }

        $contentBrief->update([
            'status' => ContentBrief::STATUS_GENERATING_SCRIPT,
            'progress' => 0,
            'message' => 'Regenerating script...',
            'failure_reason' => null,
            'narrative_title' => null,
            'narrative_hook' => null,
            'narrative_sections' => null,
            'narrative_full_script' => null,
            'narrative_suggested_description' => null,
            'narrative_suggested_hashtags' => null,
            'finished_at' => null,
        ]);

        GenerateContentBriefJob::dispatch($contentBrief->id, scriptOnly: true);

        return ContentBriefResource::make($contentBrief->fresh());
    }

    public function destroy(Request $request, ContentBrief $contentBrief)
    {
        $this->authorizeBrief($request, $contentBrief);

        if (in_array($contentBrief->status, ContentBrief::ACTIVE_STATUSES, true)) {
            $contentBrief->update(['cancel_requested' => true]);

            return response()->json(['message' => 'Cancel this content brief before deleting it. It will stop shortly.'], 422);
        }

        $contentBrief->delete();

        return response()->json(['message' => 'Content brief deleted.']);
    }

    private function authorizeBrief(Request $request, ContentBrief $contentBrief): void
    {
        if ($contentBrief->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }
    }
}
