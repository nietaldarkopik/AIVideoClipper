<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CartoonVideoResource;
use App\Jobs\GenerateCartoonStoryboardJob;
use App\Jobs\RenderCartoonSceneJob;
use App\Models\CartoonScene;
use App\Models\CartoonVideo;
use App\Models\ContentBrief;
use App\Models\ContentIdea;
use App\Services\AI\Support\ProviderChain;
use App\Services\Video\CartoonVideoStyle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CartoonVideoController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->user()->cartoonVideos()->latest();

        if ($request->filled('source_type') && $request->filled('source_id')) {
            $query->where('source_type', $request->string('source_type'))->where('source_id', $request->integer('source_id'));
        }

        return CartoonVideoResource::collection($query->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'source_type' => ['required', Rule::in(CartoonVideo::SOURCES)],
            'source_id' => ['required', 'integer'],
            'voice' => ['nullable', 'string', 'max:120'],
        ]);

        [$title, $topic] = $this->resolveSource($request, $data['source_type'], (int) $data['source_id']);

        $video = $request->user()->cartoonVideos()->create([
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'],
            'title' => mb_substr($title, 0, 255),
            'topic' => $topic,
            'style' => CartoonVideoStyle::DEFAULT,
            'voice' => $data['voice'] ?? null,
            'motion_provider' => (string) config('services.ai.scene_video_provider', 'camera_motion'),
            'status' => CartoonVideo::STATUS_PENDING,
            'message' => 'Antrean...',
        ]);

        GenerateCartoonStoryboardJob::dispatch($video->id);

        return CartoonVideoResource::make($video->load('scenes'))->response()->setStatusCode(201);
    }

    public function show(Request $request, CartoonVideo $cartoonVideo)
    {
        $this->authorizeVideo($request, $cartoonVideo);

        return CartoonVideoResource::make($cartoonVideo->load('scenes'));
    }

    public function update(Request $request, CartoonVideo $cartoonVideo)
    {
        $this->authorizeVideo($request, $cartoonVideo);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'voice' => ['sometimes', 'nullable', 'string', 'max:120'],
            'character_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        // A different voice or character means every already-generated asset is stale.
        if (array_key_exists('voice', $data) && $data['voice'] !== $cartoonVideo->voice) {
            $cartoonVideo->scenes()->update(['audio_path' => null, 'audio_duration' => null, 'clip_path' => null, 'status' => CartoonScene::STATUS_PENDING]);
        }
        if (array_key_exists('character_description', $data) && $data['character_description'] !== $cartoonVideo->character_description) {
            $cartoonVideo->scenes()->update(['image_path' => null, 'clip_path' => null, 'status' => CartoonScene::STATUS_PENDING]);
        }

        $cartoonVideo->update($data);

        return CartoonVideoResource::make($cartoonVideo->fresh()->load('scenes'));
    }

    public function updateScene(Request $request, CartoonVideo $cartoonVideo, CartoonScene $scene)
    {
        $this->authorizeVideo($request, $cartoonVideo);
        $this->ensureSceneBelongs($cartoonVideo, $scene);
        $this->ensureNotActive($cartoonVideo);

        $data = $request->validate([
            'narration' => ['sometimes', 'string', 'max:1500'],
            'visual_prompt' => ['sometimes', 'string', 'max:1500'],
            'motion_prompt' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        // Clear only what the edit invalidates, so a tweak to one scene doesn't repay for
        // the parts that are still correct.
        $stale = [];
        if (isset($data['narration']) && $data['narration'] !== $scene->narration) {
            $stale = ['audio_path' => null, 'audio_duration' => null, 'clip_path' => null];
        }
        if (isset($data['visual_prompt']) && $data['visual_prompt'] !== $scene->visual_prompt) {
            $stale = [...$stale, 'image_path' => null, 'clip_path' => null];
        }
        if (array_key_exists('motion_prompt', $data) && $data['motion_prompt'] !== $scene->motion_prompt) {
            $stale = [...$stale, 'clip_path' => null];
        }

        $scene->update([...$data, ...$stale, ...($stale ? ['status' => CartoonScene::STATUS_PENDING, 'error' => null] : [])]);

        return response()->json(['data' => CartoonVideoResource::make($cartoonVideo->fresh()->load('scenes'))]);
    }

    public function regenerateStoryboard(Request $request, CartoonVideo $cartoonVideo)
    {
        $this->authorizeVideo($request, $cartoonVideo);
        $this->ensureNotActive($cartoonVideo);

        $cartoonVideo->update(['status' => CartoonVideo::STATUS_PENDING, 'progress' => 0, 'message' => 'Antrean...', 'failure_reason' => null]);
        GenerateCartoonStoryboardJob::dispatch($cartoonVideo->id);

        return CartoonVideoResource::make($cartoonVideo->fresh()->load('scenes'));
    }

    /**
     * Start (or resume) rendering. Only scenes that aren't done yet are dispatched, so
     * calling this after a partial failure retries just the failed scenes.
     */
    public function render(Request $request, CartoonVideo $cartoonVideo)
    {
        $this->authorizeVideo($request, $cartoonVideo);

        if (! in_array($cartoonVideo->status, [
            CartoonVideo::STATUS_STORYBOARD_READY, CartoonVideo::STATUS_NEEDS_ATTENTION,
            CartoonVideo::STATUS_COMPLETED, CartoonVideo::STATUS_FAILED, CartoonVideo::STATUS_CANCELLED,
        ], true)) {
            return response()->json(['message' => 'Video ini sedang diproses atau belum punya storyboard.'], 422);
        }

        // The user may have just topped up credits or waited out a quota: forget which
        // providers recently failed so the rollover chains re-try them from the top.
        ProviderChain::resetCooldowns();

        $scenes = $cartoonVideo->scenes()->get();
        if ($scenes->isEmpty()) {
            return response()->json(['message' => 'Belum ada scene. Buat storyboard dulu.'], 422);
        }

        $todo = $scenes->where('status', '!=', CartoonScene::STATUS_DONE);

        DB::transaction(function () use ($cartoonVideo, $todo) {
            $todo->each->update(['status' => CartoonScene::STATUS_PENDING, 'error' => null]);

            $cartoonVideo->update([
                'status' => CartoonVideo::STATUS_RENDERING,
                'cancel_requested' => false,
                'progress' => 10,
                'message' => 'Merender scene...',
                'failure_reason' => null,
                'finished_at' => null,
            ]);
        });

        if ($todo->isEmpty()) {
            // Nothing stale (e.g. only re-assembling after a voice-neutral edit): go straight to assembly.
            $cartoonVideo->update(['status' => CartoonVideo::STATUS_ASSEMBLING, 'progress' => 88, 'message' => 'Merakit video...']);
            \App\Jobs\AssembleCartoonVideoJob::dispatch($cartoonVideo->id);
        } else {
            $todo->each(fn (CartoonScene $s) => RenderCartoonSceneJob::dispatch($s->id));
        }

        return CartoonVideoResource::make($cartoonVideo->fresh()->load('scenes'));
    }

    public function cancel(Request $request, CartoonVideo $cartoonVideo)
    {
        $this->authorizeVideo($request, $cartoonVideo);

        if (! in_array($cartoonVideo->status, CartoonVideo::ACTIVE_STATUSES, true)) {
            return response()->json(['message' => 'Video ini tidak sedang diproses.'], 422);
        }

        // Cooperative: running scene jobs notice the flag before their next step.
        $cartoonVideo->update(['cancel_requested' => true, 'message' => 'Membatalkan...']);

        return CartoonVideoResource::make($cartoonVideo->fresh()->load('scenes'));
    }

    public function destroy(Request $request, CartoonVideo $cartoonVideo)
    {
        $this->authorizeVideo($request, $cartoonVideo);

        if (in_array($cartoonVideo->status, CartoonVideo::ACTIVE_STATUSES, true)) {
            $cartoonVideo->update(['cancel_requested' => true]);

            return response()->json(['message' => 'Batalkan dulu video ini sebelum menghapus.'], 422);
        }

        $disk = Storage::disk('media');
        $dir = "cartoons/{$cartoonVideo->id}";

        if ($cartoonVideo->clip_id) {
            // The published clip points at final.mp4 and its thumbnail inside this
            // directory, so only the per-scene intermediates are removed.
            foreach ($cartoonVideo->scenes as $scene) {
                foreach ([$scene->image_path, $scene->audio_path, $scene->clip_path] as $path) {
                    if ($path) {
                        $disk->delete($path);
                    }
                }
            }
        } else {
            $disk->deleteDirectory($dir);
        }

        $cartoonVideo->delete();

        return response()->json(['message' => 'Video kartun dihapus.']);
    }

    /**
     * @return array{0: string, 1: string} [title, topic]
     */
    private function resolveSource(Request $request, string $type, int $id): array
    {
        $user = $request->user();

        if ($type === CartoonVideo::SOURCE_CONTENT_BRIEF) {
            $brief = ContentBrief::find($id);
            if (! $brief || ($brief->user_id !== $user->id && ! $user->isAdmin())) {
                throw new NotFoundHttpException();
            }

            return [$brief->narrative_title ?: $brief->topic, $brief->topic];
        }

        $idea = ContentIdea::find($id);
        if (! $idea || ($idea->user_id !== $user->id && ! $user->isAdmin())) {
            throw new NotFoundHttpException();
        }

        return [$idea->title, $idea->topic ?: $idea->title];
    }

    private function ensureNotActive(CartoonVideo $video): void
    {
        abort_if(in_array($video->status, CartoonVideo::ACTIVE_STATUSES, true), 422, 'Video ini sedang diproses.');
    }

    private function ensureSceneBelongs(CartoonVideo $video, CartoonScene $scene): void
    {
        if ($scene->cartoon_video_id !== $video->id) {
            throw new NotFoundHttpException();
        }
    }

    private function authorizeVideo(Request $request, CartoonVideo $video): void
    {
        if ($video->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }
    }
}
