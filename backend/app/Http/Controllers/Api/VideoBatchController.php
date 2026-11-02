<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Rules\PublicHttpUrl;
use App\Http\Resources\VideoBatchItemResource;
use App\Http\Resources\VideoBatchResource;
use App\Jobs\ProcessBatchItemJob;
use App\Models\ProcessingJob;
use App\Models\Project;
use App\Models\VideoBatch;
use App\Models\VideoBatchItem;
use App\Services\Batch\VideoBatchFactory;
use App\Services\Video\ProjectDeletionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class VideoBatchController extends Controller
{
    public function index(Request $request)
    {
        $batches = $request->user()->videoBatches()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->latest()
            ->paginate($request->integer('per_page', 12));

        return VideoBatchResource::collection($batches);
    }

    public function store(Request $request, VideoBatchFactory $batchFactory)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'urls' => ['required', 'array', 'min:1', 'max:50'],
            'urls.*' => ['nullable', 'string', 'max:2048', new PublicHttpUrl],
            'clip_mode' => ['sometimes', Rule::in(['top_3', 'top_5', 'top_10', 'all'])],
            'template_id' => ['nullable', 'exists:templates,id'],
            'aspect_ratio' => ['sometimes', Rule::in(['9:16', '1:1', '16:9'])],
            'subtitle_language' => ['sometimes', 'string', 'max:10'],
            'subtitles_enabled' => ['sometimes', 'boolean'],
            'publishing_profile_id' => ['nullable', 'exists:publishing_profiles,id'],
            'publish_stagger_min_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'publish_stagger_max_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440', 'gte:publish_stagger_min_minutes'],
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

        $batch = $batchFactory->createFromUrls($request->user(), $urls->all(), $data, $data['name'] ?? null);

        return VideoBatchResource::make($batch->load('items'))->response()->setStatusCode(201);
    }

    public function show(Request $request, VideoBatch $videoBatch)
    {
        $this->authorizeBatch($request, $videoBatch);

        $videoBatch->load([
            'items.project:id,title,status',
            // Only the running one: a finished job's last message is stale the
            // moment it completes, and the item's own message takes over then.
            'items.processingJobs' => fn ($q) => $q
                ->where('status', ProcessingJob::STATUS_RUNNING)
                ->select(['id', 'project_id', 'progress', 'message', 'status']),
        ]);

        return VideoBatchResource::make($videoBatch);
    }

    public function cancel(Request $request, VideoBatch $videoBatch)
    {
        $this->authorizeBatch($request, $videoBatch);

        if (! $this->cancelBatch($videoBatch)) {
            return response()->json(['message' => 'This batch already finished.'], 422);
        }

        return VideoBatchResource::make($videoBatch->fresh());
    }

    /**
     * Stop every selected batch that's still pending/running in one call — list-page
     * counterpart to cancel() above, same cooperative-cancel rules per batch. Batches
     * that already finished (or don't belong to the user) are silently skipped rather
     * than failing the whole request, since a bulk selection routinely mixes both.
     */
    public function bulkCancel(Request $request)
    {
        $data = $request->validate([
            'batch_ids' => ['required', 'array', 'min:1'],
            'batch_ids.*' => ['integer', 'exists:video_batches,id'],
        ]);

        $batches = $request->user()->videoBatches()->whereIn('id', $data['batch_ids'])->get();

        $cancelled = $batches->filter(fn (VideoBatch $batch) => $this->cancelBatch($batch));

        return response()->json([
            'message' => "Stopped {$cancelled->count()} batch(es).",
            'cancelled_count' => $cancelled->count(),
        ]);
    }

    /**
     * @return bool whether the batch was pending/running (and so got cancelled) —
     *              false means it had already finished and nothing changed.
     */
    private function cancelBatch(VideoBatch $videoBatch): bool
    {
        if (! in_array($videoBatch->status, [VideoBatch::STATUS_PENDING, VideoBatch::STATUS_RUNNING], true)) {
            return false;
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

        return true;
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

    public function destroy(Request $request, VideoBatch $videoBatch, ProjectDeletionService $deleter)
    {
        $this->authorizeBatch($request, $videoBatch);

        if (in_array($videoBatch->status, [VideoBatch::STATUS_PENDING, VideoBatch::STATUS_RUNNING], true)) {
            return response()->json(['message' => 'Cancel this batch before deleting it.'], 422);
        }

        $this->deleteLinkedProjects($videoBatch, $deleter);
        $videoBatch->delete();

        return response()->json(['message' => 'Batch deleted.']);
    }

    /**
     * A batch's items each optionally own one Project (populated once
     * BatchItemDownloader imports that item's video — see its docblock), which in
     * turn owns the imported source video, every rendered clip, and their files on
     * disk. video_batch_items.project_id is nullOnDelete, not cascade (see its
     * migration), so deleting the batch/items alone would leave those Projects
     * behind as orphans forever — this walks them down explicitly before the batch
     * itself goes.
     */
    private function deleteLinkedProjects(VideoBatch $videoBatch, ProjectDeletionService $deleter): void
    {
        $projectIds = $videoBatch->items()->whereNotNull('project_id')->pluck('project_id');

        Project::whereIn('id', $projectIds)->get()->each(fn (Project $project) => $deleter->delete($project));
    }

    /**
     * Same "must be stopped first" rule as destroy() above, applied per batch — a
     * still-active batch in the selection is skipped rather than failing the whole
     * request, since ProcessVideoBatchJob keeps writing to it via cancel_requested
     * polling and deleting out from under that would just orphan its DB row.
     */
    public function bulkDestroy(Request $request, ProjectDeletionService $deleter)
    {
        $data = $request->validate([
            'batch_ids' => ['required', 'array', 'min:1'],
            'batch_ids.*' => ['integer', 'exists:video_batches,id'],
        ]);

        $batches = $request->user()->videoBatches()->whereIn('id', $data['batch_ids'])->get();

        [$deletable, $active] = $batches->partition(
            fn (VideoBatch $batch) => ! in_array($batch->status, [VideoBatch::STATUS_PENDING, VideoBatch::STATUS_RUNNING], true)
        );

        foreach ($deletable as $batch) {
            $this->deleteLinkedProjects($batch, $deleter);
            $batch->delete();
        }

        return response()->json([
            'message' => $active->isEmpty()
                ? "Deleted {$deletable->count()} batch(es)."
                : "Deleted {$deletable->count()} batch(es). {$active->count()} still running were skipped — stop them first.",
            'deleted_count' => $deletable->count(),
            'skipped_count' => $active->count(),
        ]);
    }

    private function authorizeBatch(Request $request, VideoBatch $videoBatch): void
    {
        if ($videoBatch->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }
    }
}
