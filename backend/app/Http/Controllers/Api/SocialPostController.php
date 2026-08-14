<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SocialPostResource;
use App\Jobs\PublishClipJob;
use App\Models\Clip;
use App\Models\PublishingProfile;
use App\Models\SocialAccount;
use App\Models\SocialPost;
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
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $posts = $query->with('socialAccount')->latest()->paginate($request->integer('per_page', 24));

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

        return SocialPostResource::make($socialPost->load('socialAccount'));
    }

    public function retry(Request $request, SocialPost $socialPost)
    {
        $this->authorizePost($request, $socialPost);

        $socialPost->update(['status' => SocialPost::STATUS_READY, 'error_message' => null]);
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
