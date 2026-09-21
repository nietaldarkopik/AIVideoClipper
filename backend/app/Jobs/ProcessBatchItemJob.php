<?php

namespace App\Jobs;

use App\Models\Clip;
use App\Models\Project;
use App\Models\SocialPost;
use App\Models\Video;
use App\Models\VideoBatchItem;
use App\Services\Social\AutoPublishScheduler;
use App\Services\Video\BatchItemDownloader;
use App\Services\Video\ClipGenerationService;
use App\Services\Video\ProjectReprocessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Runs the transcribe/analyze -> auto-pick clips -> render -> auto-publish
 * pipeline for exactly one VideoBatchItem (downloading first too, if it isn't
 * already — see BatchItemDownloader). Called two ways:
 *  - dispatched to the queue by ProcessVideoBatchJob right after it finishes
 *    downloading this item, so this (slower, CPU-heavy) stage runs independently
 *    of the orchestrator's loop, which moves straight on to downloading the next
 *    item instead of waiting for this one to finish;
 *  - dispatched on its own by VideoBatchController::retryItem when a single failed
 *    item is retried without re-running the whole batch.
 *
 * Resumable by construction: if the item already has a project (a previous attempt
 * got partway through), reuses it via ProjectReprocessor instead of starting over,
 * and only (re-)does whatever stage actually failed — a retry never re-downloads a
 * video that already imported fine, and never re-renders a clip that already
 * completed.
 */
class ProcessBatchItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public int $videoBatchItemId)
    {
    }

    /**
     * Per-item, not global — unlike the orchestrator's lock, this must NOT block
     * this job from running while ProcessVideoBatchJob is busy downloading a later
     * item (that overlap is the whole point). It only needs to stop this exact
     * item's processing from running twice at once (e.g. a stray double retry).
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("video-batch-item-{$this->videoBatchItemId}"))->releaseAfter(30)->expireAfter(6 * 3600)];
    }

    public function handle(
        BatchItemDownloader $batchDownloader,
        ClipGenerationService $clipGeneration,
        ProjectReprocessor $reprocessor,
        AutoPublishScheduler $publishScheduler,
    ): void {
        $item = VideoBatchItem::findOrFail($this->videoBatchItemId);

        // The channel watch this item came from may have been paused AFTER this
        // job was queued (poll cadence is every 15 min — see PollChannelWatches —
        // and this pipeline's own download/transcribe/render stages routinely
        // take longer than that). PollChannelWatches already stops scanning a
        // paused watch for new uploads, but that alone doesn't stop an item that
        // was queued just before the pause from running to completion — check
        // again here, right before doing any real work, rather than only at
        // dispatch time. Manually-submitted batches have no channel_watch_id and
        // are unaffected.
        $channelWatch = $item->videoBatch?->channelWatch;
        if ($channelWatch && ! $channelWatch->is_active) {
            $item->update([
                'status' => VideoBatchItem::STATUS_CANCELLED,
                'message' => 'Skipped — the source channel watch was paused after this video was queued.',
            ]);

            Log::info('Batch item skipped — channel watch paused after queueing', [
                'item_id' => $item->id,
                'batch_id' => $item->video_batch_id,
                'channel_watch_id' => $channelWatch->id,
            ]);

            return;
        }

        $item->update(['status' => VideoBatchItem::STATUS_IMPORTING, 'progress' => 0, 'message' => 'Starting...', 'started_at' => now()]);

        Log::info('Batch item processing started', [
            'item_id' => $item->id,
            'batch_id' => $item->video_batch_id,
        ]);

        try {
            $batch = $item->videoBatch;
            $settings = $batch->settings ?? [];
            $user = $batch->user;

            [$project, $video] = $batchDownloader->ensureImported($item, $user);

            $plan = $reprocessor->plan($project);

            if (in_array($plan['stage'], [ProjectReprocessor::STAGE_IMPORT, ProjectReprocessor::STAGE_ANALYZE], true)) {
                $item->update(['status' => VideoBatchItem::STATUS_ANALYZING, 'progress' => 20, 'message' => 'Transcribing & analyzing...']);

                Log::info('Batch item: transcribing & analyzing video', [
                    'item_id' => $item->id,
                    'project_id' => $project->id,
                    'video_id' => $video->id,
                ]);

                $project = $this->runAnalyze($project, $video);

                Log::info('Batch item: analysis complete', [
                    'item_id' => $item->id,
                    'project_id' => $project->id,
                ]);
            }

            // Past the import/analyze checkpoint — whatever put this project into a
            // failed state before is resolved now. Clear it explicitly: when a retry
            // resumes straight into rendering (STAGE_RENDER/SELECT_AND_RENDER/NONE),
            // nothing else on this path would otherwise clear the stale status/reason
            // still sitting on the project row from the original failure.
            $project->update(['status' => Project::STATUS_RENDERING, 'failure_reason' => null]);

            // --- Auto-select clips (only if none exist yet — a retry reuses whatever
            // was already selected rather than picking a fresh, possibly different, set) ---
            $item->update(['status' => VideoBatchItem::STATUS_RENDERING, 'progress' => 45, 'message' => 'Selecting best moments...']);
            $clips = Clip::where('project_id', $project->id)->get();
            if ($clips->isEmpty()) {
                $clips = $clipGeneration->selectAndCreateClips($project, [
                    'mode' => $settings['clip_mode'] ?? 'top_5',
                    'template_id' => $settings['template_id'] ?? null,
                    'aspect_ratio' => $settings['aspect_ratio'] ?? '9:16',
                    'subtitle_language' => $settings['subtitle_language'] ?? 'en',
                    'subtitles_enabled' => $settings['subtitles_enabled'] ?? true,
                ]);

                Log::info('Batch item: clips selected', [
                    'item_id' => $item->id,
                    'project_id' => $project->id,
                    'clip_count' => $clips->count(),
                ]);
            }

            // --- Render whichever clips aren't already completed, one at a time ---
            [$alreadyDone, $toRender] = $clips->partition(fn (Clip $c) => $c->status === Clip::STATUS_COMPLETED);
            $renderedClips = collect($alreadyDone->values()->all());
            foreach ($toRender->values() as $index => $clip) {
                $item->update(['message' => sprintf('Rendering clip %d/%d...', $index + 1, $toRender->count())]);

                Log::info('Batch item: rendering clip', [
                    'item_id' => $item->id,
                    'clip_id' => $clip->id,
                    'position' => $index + 1,
                    'total' => $toRender->count(),
                ]);

                // Caught per-clip, not per-item: one bad render shouldn't sink the
                // other clips already queued up for this same video.
                try {
                    app()->call([new RenderClipJob($clip->id), 'handle']);
                    $clip = $clip->fresh();
                    if ($clip->status === Clip::STATUS_COMPLETED) {
                        $renderedClips->push($clip);
                        Log::info('Batch item: clip rendered', ['item_id' => $item->id, 'clip_id' => $clip->id]);
                    } else {
                        Log::warning('Batch item: clip did not complete rendering', [
                            'item_id' => $item->id,
                            'clip_id' => $clip->id,
                            'status' => $clip->status,
                            'failure_reason' => $clip->failure_reason,
                        ]);
                    }
                } catch (Throwable $e) {
                    // RenderClipJob already recorded the failure on the Clip row itself.
                    Log::warning('Batch item: clip render threw', [
                        'item_id' => $item->id,
                        'clip_id' => $clip->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $item->update(['clips_generated' => $renderedClips->count()]);

            // --- Auto-publish to active social accounts, staggered one clip at a time
            // via AutoPublishScheduler (skip destinations already published/scheduled) ---
            $postsPublished = SocialPost::whereIn('clip_id', $renderedClips->pluck('id'))
                ->where('status', SocialPost::STATUS_PUBLISHED)
                ->count();
            $finalMessage = 'Done';

            if ($renderedClips->isNotEmpty()) {
                $item->update(['status' => VideoBatchItem::STATUS_PUBLISHING, 'progress' => 85]);

                // $settings['publishing_profile_id'] is a SNAPSHOT taken when this
                // batch was created (VideoBatchFactory::createFromUrls) — correct
                // for a manually-submitted batch, which has no ongoing "source of
                // truth" to defer to, but wrong for a ChannelWatch-originated one
                // (channel_watch_id set): if the user fixes the watch's publishing
                // target AFTER this batch was queued but BEFORE this item finishes
                // rendering, the snapshot is already stale and this would otherwise
                // publish to the old (wrong) destination anyway. Resolve the LIVE
                // value from the watch in that case instead — everything else in
                // $settings (clip_mode, template_id, ...) stays a snapshot, since
                // those affect how the clip itself was already generated, not just
                // where it's about to be sent.
                $publishingProfileId = $batch->channel_watch_id
                    ? ($batch->channelWatch?->settings['publishing_profile_id'] ?? null)
                    : ($settings['publishing_profile_id'] ?? null);

                Log::info('Batch item: scheduling auto-publish', [
                    'item_id' => $item->id,
                    'project_id' => $project->id,
                    'publishing_profile_id' => $publishingProfileId,
                    'rendered_clip_count' => $renderedClips->count(),
                ]);

                $scheduledCount = $publishScheduler->scheduleForProject(
                    $project,
                    $publishingProfileId,
                    isset($settings['publish_stagger_min_minutes']) ? $settings['publish_stagger_min_minutes'] * 60 : null,
                    isset($settings['publish_stagger_max_minutes']) ? $settings['publish_stagger_max_minutes'] * 60 : null,
                );

                Log::info('Batch item: auto-publish scheduling result', [
                    'item_id' => $item->id,
                    'project_id' => $project->id,
                    'scheduled_count' => $scheduledCount,
                ]);

                if ($scheduledCount > 0) {
                    $lastScheduledAt = SocialPost::whereIn('clip_id', $renderedClips->pluck('id'))
                        ->where('status', SocialPost::STATUS_SCHEDULED)
                        ->max('scheduled_at');
                    $minutesSpanned = $lastScheduledAt ? (int) round(now()->diffInMinutes($lastScheduledAt)) : 0;
                    $finalMessage = $minutesSpanned > 0
                        ? "Rendered — publishing staggered over the next ~{$minutesSpanned} min to avoid platform rate limits."
                        : 'Done — published.';
                }
            }

            $item->update([
                'status' => VideoBatchItem::STATUS_COMPLETED,
                'progress' => 100,
                'message' => $finalMessage,
                'posts_published' => $postsPublished,
                'finished_at' => now(),
            ]);

            Log::info('Batch item processing completed', [
                'item_id' => $item->id,
                'clips_generated' => $renderedClips->count(),
                'posts_published' => $postsPublished,
            ]);
        } catch (Throwable $e) {
            Log::error('Batch item processing failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            $item->update([
                'status' => VideoBatchItem::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }

        $item->videoBatch->settleStatusIfAllItemsTerminal();
    }

    private function runAnalyze(Project $project, Video $video): Project
    {
        // autoGenerateClips: false — the batch autobot picks and renders clips
        // itself, sequentially and in-process (below), so AnalyzeVideoJob's own
        // auto-generate path (for regular, non-batch projects) must stay off here
        // to avoid rendering every clip twice via two different paths.
        app()->call([new AnalyzeVideoJob($project->id, $video->id, autoGenerateClips: false), 'handle']);
        $project = $project->fresh();
        if ($project->status !== Project::STATUS_COMPLETED) {
            throw new RuntimeException($project->failure_reason ?? 'Analysis failed.');
        }

        $project->update(['title' => $video->title ?: $project->title]);

        return $project;
    }
}
