<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelWatchResource;
use App\Models\ChannelWatch;
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

    public function store(Request $request, YouTubeChannelMonitor $monitor)
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
            // Only uploads from here on are auto-clipped — adding a watch never
            // bulk-processes a channel's existing back catalog as a surprise.
            'last_video_published_at' => now(),
        ]);

        return ChannelWatchResource::make($watch)->response()->setStatusCode(201);
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

        if (array_key_exists('is_active', $data)) {
            $channelWatch->is_active = $data['is_active'];
        }

        $channelWatch->save();

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
            ->additional(['resynced_posts' => $resyncedCount]);
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
