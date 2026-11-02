<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\Contracts\TextToSpeechProvider;
use App\Services\Video\FFmpegService;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Text -> speech for other applications, using whichever TTS provider the server is
 * configured with. Synchronous: a few seconds of audio takes a few seconds to produce.
 */
class TtsController extends Controller
{
    public function __invoke(Request $request, TextToSpeechProvider $tts, FFmpegService $ffmpeg)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:' . (int) config('integrations.tts.max_chars', 4000)],
            // Only the OpenAI TTS provider reads this; the 9Router provider picks the voice
            // from its configured model id and ignores it (see NineRouterTextToSpeechProvider).
            'voice' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $text = trim($data['text']);
        if ($text === '') {
            return response()->json(['message' => 'The text field is required.', 'errors' => ['text' => ['The text field is required.']]], 422);
        }

        $disk = Storage::disk('media');
        // Under uploads/{userId}/ so MediaAccess already authorizes the owner (and /media/sign
        // can refresh the URL), but in its own folder: `uploads/{id}/audio` is the user's
        // background-music library and these one-off files don't belong in it.
        $relative = "uploads/{$request->user()->id}/tts/" . Str::uuid() . '.mp3';
        $absolute = $disk->path($relative);

        try {
            $tts->synthesize($text, $absolute, $data['voice'] ?? null);
        } catch (Throwable $e) {
            @unlink($absolute);
            report($e);

            // 502: the failure is the upstream speech provider, not the caller's request.
            return response()->json(['message' => 'Speech synthesis failed: ' . mb_substr($e->getMessage(), 0, 300)], 502);
        }

        $duration = $ffmpeg->probeDuration($absolute);
        if (! is_file($absolute) || ! $duration) {
            @unlink($absolute);

            return response()->json(['message' => 'Speech synthesis produced no playable audio.'], 502);
        }

        return response()->json(['data' => [
            'path' => $relative,
            'url' => Media::url($relative),
            'format' => 'mp3',
            'duration_seconds' => round($duration, 2),
            'characters' => mb_strlen($text),
            'size_bytes' => filesize($absolute),
            'retention_days' => (int) config('integrations.tts.retention_days', 7),
        ]], 201);
    }
}
