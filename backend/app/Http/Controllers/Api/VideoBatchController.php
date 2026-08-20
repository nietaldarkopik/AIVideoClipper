<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoBatchItemResource;
use App\Http\Resources\VideoBatchResource;
use App\Jobs\ProcessBatchItemJob;
use App\Jobs\ProcessVideoBatchJob;
use App\Models\VideoBatch;
use App\Models\VideoBatchItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class VideoBatchController extends Controller
{
    public function index(Request $request)
    {
        $batches = $request->user()->videoBatches()
            ->latest()
            ->paginate($request->integer('per_page', 12));

        return VideoBatchResource::collection($batches);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'urls' => ['required', 'array', 'min:1', 'max:50'],
            'urls.*' => ['nullable', 'string', 'max:2048'],
            'clip_mode' => ['sometimes', Rule::in(['top_3', 'top_5', 'top_10', 'all'])],
            'template_id' => ['nullable', 'exists:templates,id'],
            'aspect_ratio' => ['sometimes', Rule::in(['9:16', '1:1', '16:9'])],
            'subtitle_language' => ['sometimes', 'string', 'max:10'],
            'subtitles_enabled' => ['sometimes', 'boolean'],
            'publishing_profile_id' => ['nullable', 'exists:publishing_profiles,id'],
        ]);

        $urls = collect($data['urls'])
            ->map(fn ($url) => trim((string) $url))
            ->filter()
            ->values();

        if ($urls->isEmpty()) {
            return response()->json(['message' => 'Provide at least one video URL.'], 422);
        }

        if (! empty($data['publishing_profile_id'])) {
            $owned = $request->user()->publishingProfiles()->whereKey($data['publishing_profile_id'])->exists();
            if (! $owned) {
                return response()->json(['message' => 'Publishing profile not found.'], 422);
            }
        }

        $batch = $request->user()->videoBatches()->create([
            'name' => $data['name'] ?? null,
            'status' => VideoBatch::STATUS_PENDING,
            'settings' => [
                'clip_mode' => $data['clip_mode'] ?? 'top_5',
                'template_id' => $data['template_id'] ?? null,
                'aspect_ratio' => $data['aspect_ratio'] ?? '9:16',
                'subtitle_language' => $data['subtitle_language'] ?? 'en',
                'subtitles_enabled' => $data['subtitles_enabled'] ?? true,
                'publishing_profile_id' => $data['publishing_profile_id'] ?? null,
            ],
            'total_items' => $urls->count(),
        ]);

        $urls->each(fn ($url, $index) => $batch->items()->create([
            'position' => $index,
            'source_url' => $url,
            'status' => VideoBatchItem::STATUS_PENDING,
        ]));

        // Its own dedicated queue/worker (see start-all.ps1/.bat) — this job only
        // downloads, one item at a time, and needs to keep running independently of
        // the 'default' worker so a batch's downloads can overlap with that same
        // batch's own items being processed (see ProcessVideoBatchJob's docblock).
        ProcessVideoBatchJob::dispatch($batch->id)->onQueue('batch-downloads');

        return VideoBatchResource::make($batch->load('items'))->response()->setStatusCode(201);
    }

    public function show(Request $request, VideoBatch $videoBatch)
    {
        $this->authorizeBatch($request, $videoBatch);

        $videoBatch->load(['items.project:id,title,status']);

        return VideoBatchResource::make($videoBatch);
    }

    public function cancel(Request $request, VideoBatch $videoBatch)
    {
        $this->authorizeBatch($request, $videoBatch);

        if (! in_array($videoBatch->status, [VideoBatch::STATUS_PENDING, VideoBatch::STATUS_RUNNING], true)) {
            return response()->json(['message' => 'This batch already finished.'], 422);
        }

        if ($videoBatch->status === VideoBatch::STATUS_PENDING) {
            $videoBatch->update(['status' => VideoBatch::STATUS_CANCELLED, 'finished_at' => now()]);
            $videoBatch->items()->where('status', VideoBatchItem::STATUS_PENDING)
                ->update(['status' => VideoBatchItem::STATUS_CANCELLED, 'finished_at' => now()]);
        } else {
            // Job is already running — flag it and let ProcessVideoBatchJob stop
            // between items on its own next check, same cooperative pattern as
            // ChecksCancellation for the manual per-project jobs.
            $videoBatch->update(['cancel_requested' => true]);
        }

        return VideoBatchResource::make($videoBatch->fresh());
    }

    /**
     * Retry a single failed item without re-running the whole batch. Resumable —
     * ProcessBatchItemJob figures out which stage actually failed (import, analyze,
     * render) and only redoes that, reusing whatever already succeeded.
     */
    public function retryItem(Request $request, VideoBatch $videoBatch, VideoBatchItem $item)
    {
        $this->authorizeBatch($request, $videoBatch);

        if ($item->video_batch_id !== $videoBatch->id) {
            throw new NotFoundHttpException();
        }

        if ($item->status !== VideoBatchItem::STATUS_FAILED) {
            return response()->json(['message' => 'Only a failed item can be retried.'], 422);
        }

        $item->update([
            'status' => VideoBatchItem::STATUS_PENDING,
            'progress' => 0,
            'message' => 'Retry queued...',
            'failure_reason' => null,
            'finished_at' => null,
        ]);

        // The batch as a whole is no longer in its finished state until this item
        // settles again — keeps the frontend polling instead of showing stale totals.
        if (in_array($videoBatch->status, [VideoBatch::STATUS_COMPLETED, VideoBatch::STATUS_COMPLETED_WITH_ERRORS, VideoBatch::STATUS_FAILED], true)) {
            $videoBatch->update(['status' => VideoBatch::STATUS_RUNNING, 'finished_at' => null]);
        }

        ProcessBatchItemJob::dispatch($item->id);

        return VideoBatchItemResource::make($item->fresh());
    }

    public function destroy(Request $request, VideoBatch $videoBatch)
    {
        $this->authorizeBatch($request, $videoBatch);

        if (in_array($videoBatch->status, [VideoBatch::STATUS_PENDING, VideoBatch::STATUS_RUNNING], true)) {
            return response()->json(['message' => 'Cancel this batch before deleting it.'], 422);
        }

        $videoBatch->delete();

        return response()->json(['message' => 'Batch deleted.']);
    }

    private function authorizeBatch(Request $request, VideoBatch $videoBatch): void
    {
        if ($videoBatch->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }
    }
}
