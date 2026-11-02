<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssistantConversationResource;
use App\Jobs\RunAssistantTurnJob;
use App\Models\AssistantAction;
use App\Models\AssistantAttachment;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Services\Assistant\AttachmentProcessor;
use App\Services\Assistant\ToolCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AssistantController extends Controller
{
    public function config(ToolCatalog $catalog)
    {
        return response()->json([
            'provider' => config('services.ai.assistant_provider', 'mock'),
            'configured' => config('services.ai.assistant_provider', 'mock') !== 'mock',
            'tool_count' => count($catalog->all()),
            'max_files' => (int) config('assistant.max_files_per_message'),
            'max_file_mb' => (int) config('assistant.max_file_mb'),
            'accepted_formats' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'docx', 'xlsx', 'csv', 'tsv', 'txt', 'md', 'json', 'srt', 'vtt', 'html', 'xml'],
        ]);
    }

    public function index(Request $request)
    {
        $conversations = $request->user()->assistantConversations()
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 30));

        return AssistantConversationResource::collection($conversations);
    }

    public function show(Request $request, AssistantConversation $conversation)
    {
        $this->authorizeConversation($request, $conversation);

        return AssistantConversationResource::make($conversation->load(['messages.attachments', 'actions']));
    }

    public function update(Request $request, AssistantConversation $conversation)
    {
        $this->authorizeConversation($request, $conversation);
        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);
        $conversation->update($data);

        return AssistantConversationResource::make($conversation);
    }

    public function destroy(Request $request, AssistantConversation $conversation)
    {
        $this->authorizeConversation($request, $conversation);

        if ($conversation->status === AssistantConversation::STATUS_RUNNING) {
            return response()->json(['message' => 'Percakapan sedang diproses. Hentikan dulu sebelum menghapus.'], 422);
        }

        $disk = Storage::disk('media');
        AssistantAttachment::whereIn('assistant_message_id', $conversation->messages()->pluck('id'))
            ->get()
            ->each(function (AssistantAttachment $a) use ($disk) {
                $disk->delete([$a->path, $a->path . '.vision.jpg']);
            });

        $conversation->delete();

        return response()->json(['message' => 'Percakapan dihapus.']);
    }

    /**
     * Upload files ahead of sending, so they can be shown attached (and rejected early)
     * before the message goes out. They stay unlinked until a message claims them.
     */
    public function uploadAttachments(Request $request, AttachmentProcessor $processor)
    {
        $maxKb = (int) config('assistant.max_file_mb', 20) * 1024;

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:' . (int) config('assistant.max_files_per_message', 8)],
            'files.*' => ['file', 'max:' . $maxKb],
        ]);

        $attachments = collect($request->file('files'))
            ->map(fn ($file) => $processor->process($file, $request->user()));

        return response()->json(['data' => $attachments->map(fn ($a) => AssistantConversationResource::attachment($a))->values()], 201);
    }

    public function sendMessage(Request $request)
    {
        $data = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'content' => ['nullable', 'string', 'max:20000'],
            'attachment_ids' => ['sometimes', 'array', 'max:' . (int) config('assistant.max_files_per_message', 8)],
            'attachment_ids.*' => ['integer'],
        ]);

        $user = $request->user();
        $content = trim((string) ($data['content'] ?? ''));
        $attachmentIds = $data['attachment_ids'] ?? [];

        if ($content === '' && $attachmentIds === []) {
            return response()->json(['message' => 'Tulis pesan atau lampirkan file.'], 422);
        }

        $attachments = AssistantAttachment::where('user_id', $user->id)
            ->whereNull('assistant_message_id')
            ->whereIn('id', $attachmentIds)
            ->get();

        if ($attachments->count() !== count(array_unique($attachmentIds))) {
            return response()->json(['message' => 'Sebagian lampiran tidak ditemukan atau sudah terpakai.'], 422);
        }

        $conversation = null;

        if (! empty($data['conversation_id'])) {
            $conversation = AssistantConversation::find($data['conversation_id']);
            $this->authorizeConversation($request, $conversation);
        }

        $message = DB::transaction(function () use (&$conversation, $user, $content, $attachments) {
            // Locking the conversation row makes "is it busy?" and "claim it" one step, so
            // two quick sends can't both start a run.
            if ($conversation) {
                $conversation = AssistantConversation::whereKey($conversation->id)->lockForUpdate()->first();

                if ($conversation->status === AssistantConversation::STATUS_RUNNING) {
                    abort(422, 'Asisten masih memproses pesan sebelumnya.');
                }
                if ($conversation->status === AssistantConversation::STATUS_AWAITING_APPROVAL) {
                    abort(422, 'Ada tindakan yang menunggu persetujuan. Setujui atau tolak dulu sebelum melanjutkan.');
                }
            } else {
                $conversation = $user->assistantConversations()->create([
                    'title' => mb_substr($content !== '' ? $content : ($attachments->first()?->original_name ?? 'Percakapan baru'), 0, 80),
                ]);
            }

            $message = $conversation->messages()->create(['role' => AssistantMessage::ROLE_USER, 'content' => $content]);
            AssistantAttachment::whereIn('id', $attachments->pluck('id'))->update(['assistant_message_id' => $message->id]);
            $conversation->update(['status' => AssistantConversation::STATUS_RUNNING, 'failure_reason' => null, 'cancel_requested' => false, 'last_message_at' => now()]);

            return $message;
        });

        RunAssistantTurnJob::dispatch($conversation->id);

        return AssistantConversationResource::make($conversation->load(['messages.attachments', 'actions']))->response()->setStatusCode(202);
    }

    /**
     * Approve/reject pending actions. The run resumes only once EVERY pending action of the
     * conversation has been decided — the model must see a result for each tool call it made.
     */
    public function decide(Request $request, AssistantConversation $conversation)
    {
        $this->authorizeConversation($request, $conversation);

        $data = $request->validate([
            'decisions' => ['required', 'array', 'min:1'],
            'decisions.*' => ['required', Rule::in(['approve', 'reject'])],
        ]);

        $resume = DB::transaction(function () use ($conversation, $data) {
            $locked = AssistantConversation::whereKey($conversation->id)->lockForUpdate()->first();

            if ($locked->status !== AssistantConversation::STATUS_AWAITING_APPROVAL) {
                abort(422, 'Tidak ada tindakan yang menunggu persetujuan.');
            }

            foreach ($data['decisions'] as $actionId => $decision) {
                $action = $locked->actions()->where('status', AssistantAction::STATUS_PENDING)->find((int) $actionId);

                if ($action) {
                    $action->update([
                        'status' => $decision === 'approve' ? AssistantAction::STATUS_APPROVED : AssistantAction::STATUS_REJECTED,
                        'decided_at' => now(),
                    ]);
                }
            }

            if ($locked->actions()->where('status', AssistantAction::STATUS_PENDING)->exists()) {
                return false;
            }

            $locked->update(['status' => AssistantConversation::STATUS_RUNNING, 'failure_reason' => null]);

            return true;
        });

        if ($resume) {
            RunAssistantTurnJob::dispatch($conversation->id);
        }

        return AssistantConversationResource::make($conversation->fresh()->load(['messages.attachments', 'actions']));
    }

    /** Cooperative stop: the run checks the flag before each model call. */
    public function stop(Request $request, AssistantConversation $conversation)
    {
        $this->authorizeConversation($request, $conversation);

        if ($conversation->status === AssistantConversation::STATUS_RUNNING) {
            $conversation->update(['cancel_requested' => true]);
        } elseif ($conversation->status === AssistantConversation::STATUS_AWAITING_APPROVAL) {
            // Nothing is running: stopping means declining everything that is waiting.
            $conversation->actions()->where('status', AssistantAction::STATUS_PENDING)
                ->update(['status' => AssistantAction::STATUS_REJECTED, 'decided_at' => now()]);
            $conversation->update(['status' => AssistantConversation::STATUS_RUNNING]);
            RunAssistantTurnJob::dispatch($conversation->id);
        }

        return AssistantConversationResource::make($conversation->fresh()->load(['messages.attachments', 'actions']));
    }

    private function authorizeConversation(Request $request, ?AssistantConversation $conversation): void
    {
        if (! $conversation || $conversation->user_id !== $request->user()->id) {
            throw new NotFoundHttpException;
        }
    }
}
