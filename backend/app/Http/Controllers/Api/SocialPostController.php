<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SocialPostResource;
use App\Jobs\PublishClipJob;
use App\Models\Clip;
use App\Models\PublishingProfile;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\AutoPublishScheduler;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SocialPostController extends Controller
{
    public function index(Request $request)
    {
        $query = SocialPost::whereHas('clip.project', fn ($q) => $q->where('user_id', $request->user()->id));

        if ($request->filled('clip_id')) {
            $query->where('clip_id', $request->integer('clip_id'));
        }
        if ($request->filled('project_id')) {
            $query->whereHas('clip', fn ($q) => $q->where('project_id', $request->integer('project_id')));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('platform')) {
            $query->where('platform', $request->string('platform'));
        }
        if ($request->filled('social_account_id')) {
            $query->where('social_account_id', $request->integer('social_account_id'));
        }
        if ($request->filled('scheduled_from')) {
            $query->where('scheduled_at', '>=', Carbon::parse($request->string('scheduled_from')));
        }
        if ($request->filled('scheduled_to')) {
            $query->where('scheduled_at', '<=', Carbon::parse($request->string('scheduled_to')));
        }

        // Sorted by scheduled_at (soonest first, nulls last) rather than
        // created_at — the whole point of this endpoint is "what's coming up",
        // and with per_page capped, ordering by creation time meant an old post
        // freshly rescheduled into the near future could sit behind hundreds of
        // more-recently-created rows and never actually reach the response.
        $posts = $query->with(['socialAccount', 'clip.project'])
            ->orderByRaw('scheduled_at IS NULL')
            ->orderBy('scheduled_at')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 24));

        return SocialPostResource::collection($posts);
    }

    /**
     * Publish one clip to one or more destinations (explicit account_ids and/or a
     * publishing profile), each as its own job so platform failures stay isolated.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'clip_id' => ['required', 'exists:clips,id'],
            'social_account_ids' => ['sometimes', 'array'],
            'social_account_ids.*' => ['integer', 'exists:social_accounts,id'],
            'publishing_profile_id' => ['sometimes', 'nullable', 'exists:publishing_profiles,id'],
            'caption_overrides' => ['sometimes', 'array'], // { [social_account_id]: { title?, caption?, hashtags? } }
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $clip = Clip::findOrFail($data['clip_id']);
        if ($clip->project->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }
        if ($clip->status !== Clip::STATUS_COMPLETED) {
            return response()->json(['message' => 'Clip must finish rendering before it can be published.'], 422);
        }

        $accountIds = collect($data['social_account_ids'] ?? []);

        if (! empty($data['publishing_profile_id'])) {
            $profile = PublishingProfile::with('socialAccounts')->find($data['publishing_profile_id']);
            if ($profile && $profile->user_id === $request->user()->id) {
                $accountIds = $accountIds->merge($profile->socialAccounts->pluck('id'));
            }
        }

        $accounts = SocialAccount::where('user_id', $request->user()->id)
            ->whereIn('id', $accountIds->unique())
            ->where('status', SocialAccount::STATUS_CONNECTED)
            ->get();

        if ($accounts->isEmpty()) {
            return response()->json(['message' => 'Select at least one connected account to publish to.'], 422);
        }

        $scheduledAt = ! empty($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null;
        $overrides = $data['caption_overrides'] ?? [];

        $posts = [];
        foreach ($accounts as $account) {
            $override = $overrides[$account->id] ?? [];

            $post = SocialPost::create([
                'clip_id' => $clip->id,
                'social_account_id' => $account->id,
                'platform' => $account->platform,
                'title' => $override['title'] ?? $clip->title,
                'caption' => $override['caption'] ?? $clip->caption,
                'hashtags' => $override['hashtags'] ?? $clip->hashtags,
                'status' => $scheduledAt ? SocialPost::STATUS_SCHEDULED : SocialPost::STATUS_READY,
                'scheduled_at' => $scheduledAt,
            ]);

            if ($scheduledAt && $scheduledAt->isFuture()) {
                PublishClipJob::dispatch($post->id)->delay($scheduledAt);
            } else {
                PublishClipJob::dispatch($post->id);
            }

            $posts[] = $post;
        }

        return SocialPostResource::collection(collect($posts))->response()->setStatusCode(201);
    }

    public function show(Request $request, SocialPost $socialPost)
    {
        $this->authorizePost($request, $socialPost);

        return SocialPostResource::make($socialPost->load(['socialAccount', 'clip.project']));
    }

    /**
     * Edit an existing schedule: retarget the account/platform, tweak
     * title/caption/hashtags, and/or reschedule scheduled_at. Rejects edits once
     * the post is already published or actively in flight — see authorizePost()
     * for ownership. A changed scheduled_at dispatches a fresh delayed
     * PublishClipJob; the stale original delayed job (still sitting in the queue
     * at the old time) is a guarded no-op — see PublishClipJob::handle().
     */
    public function update(Request $request, SocialPost $socialPost)
    {
        $this->authorizePost($request, $socialPost);

        if (in_array($socialPost->status, [SocialPost::STATUS_PUBLISHED, SocialPost::STATUS_UPLOADING, SocialPost::STATUS_PUBLISHING], true)) {
            return response()->json(['message' => 'Cannot edit a post that is already published or in progress.'], 422);
        }

        $data = $request->validate([
            'social_account_id' => ['sometimes', 'exists:social_accounts,id'],
            'title' => ['sometimes', 'nullable', 'string'],
            'caption' => ['sometimes', 'nullable', 'string'],
            'hashtags' => ['sometimes', 'array'],
            'scheduled_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        if (array_key_exists('social_account_id', $data)) {
            $account = SocialAccount::where('user_id', $request->user()->id)
                ->where('status', SocialAccount::STATUS_CONNECTED)
                ->findOrFail($data['social_account_id']);
            $socialPost->social_account_id = $account->id;
            $socialPost->platform = $account->platform;
        }

        $socialPost->fill(collect($data)->only(['title', 'caption', 'hashtags'])->all());

        if (array_key_exists('scheduled_at', $data)) {
            $scheduledAt = $data['scheduled_at'] ? Carbon::parse($data['scheduled_at']) : null;
            $socialPost->scheduled_at = $scheduledAt;
            $socialPost->status = $scheduledAt ? SocialPost::STATUS_SCHEDULED : SocialPost::STATUS_READY;
            $socialPost->save();

            if ($scheduledAt) {
                PublishClipJob::dispatch($socialPost->id)->delay($scheduledAt);
            }
        } else {
            $socialPost->save();
        }

        return SocialPostResource::make($socialPost->fresh(['socialAccount', 'clip.project']));
    }

    /**
     * Recompute scheduled_at via AutoPublishScheduler::nextAvailableSlot() — the
     * same stagger/day-cap logic the auto-scheduler used when this post was first
     * created — instead of publishing outright (that's publish-now). Two use
     * cases, same mechanism: after retargeting to a different account (the old
     * slot was picked for the OLD account's queue, so it's stale for the new
     * one), or to bump a good clip to the soonest non-spammy slot ("prioritize")
     * without skipping the anti-spam pacing entirely.
     */
    public function regenerateSchedule(Request $request, SocialPost $socialPost, AutoPublishScheduler $scheduler)
    {
        $this->authorizePost($request, $socialPost);

        if (in_array($socialPost->status, [SocialPost::STATUS_PUBLISHED, SocialPost::STATUS_UPLOADING, SocialPost::STATUS_PUBLISHING], true)) {
            return response()->json(['message' => 'Cannot reschedule a post that is already published or in progress.'], 422);
        }

        $data = $request->validate([
            'social_account_id' => ['sometimes', 'exists:social_accounts,id'],
        ]);

        $account = $socialPost->socialAccount;
        if (array_key_exists('social_account_id', $data)) {
            $account = SocialAccount::where('user_id', $request->user()->id)
                ->where('status', SocialAccount::STATUS_CONNECTED)
                ->findOrFail($data['social_account_id']);
            $socialPost->social_account_id = $account->id;
            $socialPost->platform = $account->platform;
        }

        $slot = $scheduler->nextAvailableSlot($account, now(), AutoPublishScheduler::MAX_POSTS_PER_DAY_PER_ACCOUNT);
        $socialPost->scheduled_at = $slot;
        $socialPost->status = SocialPost::STATUS_SCHEDULED;
        $socialPost->save();

        // Stale original delayed job (if this post already had one) is a guarded
        // no-op — see PublishClipJob::handle()'s scheduled_at->isFuture() check.
        PublishClipJob::dispatch($socialPost->id)->delay($slot);

        return SocialPostResource::make($socialPost->fresh(['socialAccount', 'clip.project']));
    }

    /**
     * "Reschedule All" — bulk-redistribute a caller-chosen set of existing
     * posts, staggered per social account with a random gap (default 30min-2h)
     * via AutoPublishScheduler::rescheduleBulk(). Posts already published or
     * in-flight, or that don't belong to the caller, are silently skipped
     * rather than failing the whole batch (there's no per-post feedback in a
     * bulk op, and skipping is the safe default — unlike a single edit, this
     * touches many rows the user only skimmed, not each individually).
     */
    public function bulkReschedule(Request $request, AutoPublishScheduler $scheduler)
    {
        $data = $request->validate([
            'social_post_ids' => ['required', 'array', 'min:1'],
            'social_post_ids.*' => ['integer', 'exists:social_posts,id'],
            'min_minutes' => ['sometimes', 'integer', 'min:1'],
            'max_minutes' => ['sometimes', 'integer', 'min:1'],
            'max_per_day' => ['sometimes', 'integer', 'min:1'],
            // Daily posting window (both optional — a window only activates
            // when BOTH are present, see AutoPublishScheduler::nextAvailableSlot();
            // if only one is sent, the other stays null and the window is simply
            // ignored rather than erroring). window_end_hour goes up to 24
            // (midnight) so a caller can express "post until end of day".
            'window_start_hour' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:23'],
            'window_end_hour' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],
        ]);

        if (
            isset($data['window_start_hour'], $data['window_end_hour'])
            && $data['window_start_hour'] >= $data['window_end_hour']
        ) {
            return response()->json(['message' => 'window_end_hour must be after window_start_hour.'], 422);
        }

        $posts = SocialPost::with('socialAccount')
            ->whereIn('id', $data['social_post_ids'])
            ->whereHas('clip.project', fn ($q) => $q->where('user_id', $request->user()->id))
            ->whereNotIn('status', [SocialPost::STATUS_PUBLISHED, SocialPost::STATUS_UPLOADING, SocialPost::STATUS_PUBLISHING])
            ->get();

        $minSeconds = (int) ($data['min_minutes'] ?? 30) * 60;
        $maxSeconds = (int) ($data['max_minutes'] ?? 120) * 60;
        $maxPerDay = (int) ($data['max_per_day'] ?? AutoPublishScheduler::MAX_POSTS_PER_DAY_PER_ACCOUNT);
        $windowStartHour = $data['window_start_hour'] ?? null;
        $windowEndHour = $data['window_end_hour'] ?? null;

        $count = $scheduler->rescheduleBulk($posts, $minSeconds, $maxSeconds, $maxPerDay, $windowStartHour, $windowEndHour);

        return response()->json(['message' => "Rescheduled {$count} post(s).", 'rescheduled_count' => $count]);
    }

    public function retry(Request $request, SocialPost $socialPost)
    {
        $this->authorizePost($request, $socialPost);

        $socialPost->update(['status' => SocialPost::STATUS_READY, 'error_message' => null]);
        PublishClipJob::dispatch($socialPost->id);

        return SocialPostResource::make($socialPost->fresh());
    }

    /**
     * Override an auto-scheduled post's staggered delay and publish it right away —
     * the "publish now if you don't want to wait" escape hatch for auto-scheduled
     * (batch autobot or regular-project auto-publish) posts.
     */
    public function publishNow(Request $request, SocialPost $socialPost)
    {
        $this->authorizePost($request, $socialPost);

        if (in_array($socialPost->status, [SocialPost::STATUS_PUBLISHED, SocialPost::STATUS_UPLOADING, SocialPost::STATUS_PUBLISHING], true)) {
            return response()->json(['message' => 'This post is already published or in progress.'], 422);
        }

        $socialPost->update(['status' => SocialPost::STATUS_READY, 'scheduled_at' => null, 'error_message' => null]);
        PublishClipJob::dispatch($socialPost->id);

        return SocialPostResource::make($socialPost->fresh());
    }

    public function destroy(Request $request, SocialPost $socialPost)
    {
        $this->authorizePost($request, $socialPost);
        $socialPost->delete();

        return response()->json(['message' => 'Scheduled post cancelled.']);
    }

    private function authorizePost(Request $request, SocialPost $post): void
    {
        if ($post->clip->project->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }
    }
}
