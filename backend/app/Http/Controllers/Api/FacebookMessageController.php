<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FacebookMessageResource;
use App\Http\Resources\Research\ContentIdeaResource;
use App\Models\ContentChannel;
use App\Models\ContentIdea;
use App\Models\FacebookMessage;
use App\Models\SocialAccount;
use App\Services\Social\FacebookMessengerSync;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FacebookMessageController extends Controller
{
    public function index(Request $request)
    {
        $messages = FacebookMessage::query()
            ->whereHas('socialAccount', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with('socialAccount:id,account_name,avatar_url')
            ->when($request->filled('social_account_id'), fn ($q) => $q->where('social_account_id', $request->integer('social_account_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('sent_at')
            ->paginate(min(100, $request->integer('per_page', 20)));

        return FacebookMessageResource::collection($messages);
    }

    public function sync(Request $request, SocialAccount $socialAccount, FacebookMessengerSync $sync)
    {
        $this->authorizeAccount($request, $socialAccount);

        $inserted = $sync->syncAccount($socialAccount);

        return response()->json(['inserted' => $inserted]);
    }

    public function convertToIdea(Request $request, FacebookMessage $facebookMessage)
    {
        $this->authorizeMessage($request, $facebookMessage);

        $data = $request->validate([
            'content_channel_id' => [
                'required',
                Rule::exists('content_channels', 'id')->where('user_id', $request->user()->id),
            ],
            'title' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $channel = ContentChannel::findOrFail($data['content_channel_id']);
        $text = trim((string) $facebookMessage->message);
        $title = $data['title'] ?? Str::limit($text, 100, '');

        $idea = ContentIdea::create([
            'content_channel_id' => $channel->id,
            'user_id' => $request->user()->id,
            'research_date' => now()->toDateString(),
            'topic' => $title,
            'title' => $title,
            'short_description' => Str::limit($text, 500),
            'source_summary' => $text,
            'status' => ContentIdea::STATUS_IDEA,
            'notes' => "Sourced from a Facebook Messenger DM to {$facebookMessage->socialAccount->account_name}.",
            'fingerprint' => Str::slug(Str::limit($title, 80, '')),
        ]);

        $facebookMessage->update([
            'content_idea_id' => $idea->id,
            'status' => FacebookMessage::STATUS_CONVERTED,
        ]);

        return ContentIdeaResource::make($idea)->response()->setStatusCode(201);
    }

    public function ignore(Request $request, FacebookMessage $facebookMessage)
    {
        $this->authorizeMessage($request, $facebookMessage);

        $facebookMessage->update(['status' => FacebookMessage::STATUS_IGNORED]);

        return FacebookMessageResource::make($facebookMessage->fresh());
    }

    private function authorizeAccount(Request $request, SocialAccount $account): void
    {
        if ($account->user_id !== $request->user()->id) {
            throw new NotFoundHttpException;
        }
    }

    private function authorizeMessage(Request $request, FacebookMessage $message): void
    {
        if ($message->socialAccount->user_id !== $request->user()->id) {
            throw new NotFoundHttpException;
        }
    }
}
