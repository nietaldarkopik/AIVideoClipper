<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelWatchResource;
use App\Models\ChannelWatch;
use App\Models\VideoBatchItem;
use App\Services\Channel\ChannelWatchPoller;
use App\Services\Channel\YouTubeChannelMonitor;
use App\Services\Social\AutoPublishScheduler;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ChannelWatchController extends Controller
{
    public function index(Request $request)
    {
        $watches = $request->user()->channelWatches()->latest()->get();

        return ChannelWatchResource::collection($watches);
    }

    public function store(Request $request, YouTubeChannelMonitor $monitor, ChannelWatchPoller $poller)
    {
        $data = $request->validate([
            'channel_url' => ['required', 'string', 'max:2048'],
            'clip_mode' => ['sometimes', Rule::in(['top_3', 'top_5', 'top_10', 'all'])],
            'template_id' => ['nullable', 'exists:templates,id'],
            'aspect_ratio' => ['sometimes', Rule::in(['9:16', '1:1', '16:9'])],
            'subtitle_language' => ['sometimes', 'string', 'max:10'],
            'subtitles_enabled' => ['sometimes', 'boolean'],
            'publishing_profile_id' => ['nullable', 'exists:publishing_profiles,id'],
            'publish_stagger_min_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'publish_stagger_max_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440', 'gte:publish_stagger_min_minutes'],
        ]);

        if (! empty($data['publishing_profile_id'])) {
            $owned = $request->user()->publishingProfiles()->whereKey($data['publishing_profile_id'])->exists();
            if (! $owned) {
                return response()->json(['message' => 'Publishing profile not found.'], 422);
            }
        }

        try {
            $channel = $monitor->resolveChannel(trim($data['channel_url']));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $exists = $request->user()->channelWatches()
            ->where('platform', 'youtube')
            ->where('channel_id', $channel['channel_id'])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'You\'re already watching this channel.'], 422);
        }

        $watch = $request->user()->channelWatches()->create([
            'platform' => 'youtube',
            'channel_id' => $channel['channel_id'],
            'channel_title' => $channel['title'],
            'channel_url' => $data['channel_url'],
            'thumbnail_url' => $channel['thumbnail_url'],
            'uploads_playlist_id' => $channel['uploads_playlist_id'],
            'is_active' => true,
            'settings' => [
                'clip_mode' => $data['clip_mode'] ?? 'top_5',
                'template_id' => $data['template_id'] ?? null,
                'aspect_ratio' => $data['aspect_ratio'] ?? '9:16',
                'subtitle_language' => $data['subtitle_language'] ?? 'en',
                'subtitles_enabled' => $data['subtitles_enabled'] ?? true,
                'publishing_profile_id' => $data['publishing_profile_id'] ?? null,
                'publish_stagger_min_minutes' => $data['publish_stagger_min_minutes'] ?? AutoPublishScheduler::STAGGER_MIN_SECONDS / 60,
                'publish_stagger_max_minutes' => $data['publish_stagger_max_minutes'] ?? AutoPublishScheduler::STAGGER_MAX_SECONDS / 60,
            ],
            // queueLatestVideo() below immediately overwrites this with the
            // channel's actual latest video (if any) — this is only the fallback
            // watermark for a channel with no qualifying uploads at all yet.
            'last_video_published_at' => now(),
        ]);

        // Immediately queue the channel's current latest video (never its whole
        // back catalog — see queueLatestVideo()'s docblock) so adding a watch
        // visibly does something right away instead of silently waiting for the
        // channel's next organic upload, which can be days off.
        $poller->queueLatestVideo($watch);

        return ChannelWatchResource::make($watch->fresh())->response()->setStatusCode(201);
    }

    public function update(Request $request, ChannelWatch $channelWatch)
    {
        $this->authorizeWatch($request, $channelWatch);

        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'clip_mode' => ['sometimes', Rule::in(['top_3', 'top_5', 'top_10', 'all'])],
            'template_id' => ['nullable', 'exists:templates,id'],
            'aspect_ratio' => ['sometimes', Rule::in(['9:16', '1:1', '16:9'])],
            'subtitle_language' => ['sometimes', 'string', 'max:10'],
            'subtitles_enabled' => ['sometimes', 'boolean'],
            'publishing_profile_id' => ['nullable', 'exists:publishing_profiles,id'],
            'publish_stagger_min_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'publish_stagger_max_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440', 'gte:publish_stagger_min_minutes'],
        ]);

        if (array_key_exists('publishing_profile_id', $data) && $data['publishing_profile_id']) {
            $owned = $request->user()->publishingProfiles()->whereKey($data['publishing_profile_id'])->exists();
            if (! $owned) {
                return response()->json(['message' => 'Publishing profile not found.'], 422);
            }
        }

        $oldPublishingProfileId = $channelWatch->settings['publishing_profile_id'] ?? null;

        $settingsKeys = [
            'clip_mode', 'template_id', 'aspect_ratio', 'subtitle_language', 'subtitles_enabled',
            'publishing_profile_id', 'publish_stagger_min_minutes', 'publish_stagger_max_minutes',
        ];
        $settingsUpdate = collect($data)->only($settingsKeys);
        if ($settingsUpdate->isNotEmpty()) {
            $channelWatch->settings = [...($channelWatch->settings ?? []), ...$settingsUpdate->all()];
        }

        $pausing = array_key_exists('is_active', $data) && $data['is_active'] === false && $channelWatch->is_active;

        if (array_key_exists('is_active', $data)) {
            $channelWatch->is_active = $data['is_active'];
        }

        $channelWatch->save();

        // Stop scanning happens immediately (PollChannelWatches filters on
        // is_active), but a video from this channel can already be sitting in
        // the queue from an earlier poll — cancel those now rather than leaving
        // them to run to completion. ProcessBatchItemJob has its own is_active
        // check for the case where an item is already mid-run when this fires
        // (can't be interrupted synchronously from here); this only covers ones
        // that haven't started yet.
        $cancelledCount = 0;
        if ($pausing) {
            $cancelledCount = VideoBatchItem::whereHas(
                'videoBatch',
                fn ($q) => $q->where('channel_watch_id', $channelWatch->id)
            )
                ->where('status', VideoBatchItem::STATUS_PENDING)
                ->update([
                    'status' => VideoBatchItem::STATUS_CANCELLED,
                    'message' => 'Skipped — the source channel watch was paused.',
                ]);
        }

        // The publishing target just got corrected — redirect whatever this
        // channel's already-rendered-but-not-yet-published clips are still
        // scheduled to, onto the new target(s), instead of leaving them queued
        // to publish to the old (wrong) account. See
        // AutoPublishScheduler::resyncChannelWatchProfile()'s docblock for what
        // this does and doesn't cover.
        $newPublishingProfileId = $channelWatch->settings['publishing_profile_id'] ?? null;
        $resyncedCount = 0;
        if (array_key_exists('publishing_profile_id', $data) && $newPublishingProfileId !== $oldPublishingProfileId) {
            $resyncedCount = app(AutoPublishScheduler::class)->resyncChannelWatchProfile($channelWatch, $newPublishingProfileId);
        }

        return ChannelWatchResource::make($channelWatch->fresh())
            ->additional(['resynced_posts' => $resyncedCount, 'cancelled_items' => $cancelledCount]);
    }

    public function destroy(Request $request, ChannelWatch $channelWatch)
    {
        $this->authorizeWatch($request, $channelWatch);
        $channelWatch->delete();

        return response()->json(['message' => 'Channel watch deleted.']);
    }

    public function checkNow(Request $request, ChannelWatch $channelWatch, ChannelWatchPoller $poller)
    {
        $this->authorizeWatch($request, $channelWatch);

        $poller->pollOne($channelWatch);

        return ChannelWatchResource::make($channelWatch->fresh());
    }

    private function authorizeWatch(Request $request, ChannelWatch $channelWatch): void
    {
        if ($channelWatch->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }
    }
}
