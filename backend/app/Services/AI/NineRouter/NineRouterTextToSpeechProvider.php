<?php

namespace App\Services\AI\NineRouter;

use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Contracts\TextToSpeechProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real TTS via a self-hosted 9Router instance's OpenAI-compatible /audio/speech
 * endpoint, pointed at a configurable base URL. Confirmed working against both a
 * 9Router-registered OpenAI credential ("openai/gpt-4o-mini-tts") and a Gemini one
 * ("gemini/gemini-3.1-flash-tts-preview/Zephyr") — GET {base_url}/models/tts lists
 * whatever your instance actually has registered. Two things differ from
 * OpenAITextToSpeechProvider:
 * - Voice is picked by appending it to the model id itself for models that need
 *   one ("{model}/{voice}"), not a separate request field — the $voice param this
 *   class's synthesize() takes (OpenAI-style voice names like "alloy", from
 *   Clip::intro_voice) doesn't map onto every provider's own voice names, so it's
 *   intentionally unused here; pick the voice by baking it into a model id below.
 * - response_format is ignored server-side — audio always comes back as WAV
 *   regardless of what's requested. That's fine to save under a ".mp3" path
 *   (see TextToSpeechProvider callers): ffmpeg content-sniffs the container
 *   rather than trusting the file extension, so a misnamed WAV still decodes
 *   correctly everywhere downstream (renderCoverSegment, probeDuration).
 *
 * $models is an ordered fallback chain, not just one id — each upstream credential
 * behind 9Router has its own separate quota (OpenAI's and Gemini's free/paid tiers
 * are exhausted independently), so trying the next model on failure survives
 * either one being temporarily rate-limited or over quota without failing the
 * whole synthesize() call. Only throws once every model in the chain has failed —
 * callers (RenderClipJob, ClipController::generateReactionScript) treat that as
 * "no TTS available right now" and skip narration rather than failing the clip.
 */
class NineRouterTextToSpeechProvider implements TextToSpeechProvider
{
    use LogsAiRequests;

    /**
     * @param  list<string>  $models  tried in order; first success wins
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly array $models = [],
    ) {
    }

    public function synthesize(string $text, string $outPath, ?string $voice = null): void
    {
        if (empty($this->models)) {
            throw new RuntimeException(
                'NINE_ROUTER_TTS_MODEL is not set. Check GET ' . rtrim($this->baseUrl, '/') .
                '/models/tts for the TTS model ids your 9Router instance actually has credentials for.'
            );
        }

        $dir = dirname($outPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $failures = [];

        foreach ($this->models as $model) {
            $span = $this->aiLogger()->start('tts', 'nine_router', $model, $text);

            try {
                $request = Http::timeout(120)
                    // retry(2, ...) throws RequestException on a persistently-failing
                    // response by default (unlike retry(1, ...), which returns it as
                    // a normal failed() response) — caught below so a quota/rate-limit
                    // error on this model falls through to the next one instead of
                    // escaping synthesize() on the very first attempt.
                    ->retry(2, 1500)
                    ->withOptions(['version' => 1.1]);

                if ($this->apiKey) {
                    $request = $request->withToken($this->apiKey);
                }

                $response = $request->post(rtrim($this->baseUrl, '/') . '/audio/speech', [
                    'model' => $model,
                    'input' => $text,
                ]);
            } catch (\Throwable $e) {
                $span->failure($e->getMessage());
                $failures[] = "[{$model}] " . $e->getMessage();

                continue;
            }

            if ($response->failed()) {
                $span->failure($response->body());
                $failures[] = "[{$model}] " . $response->body();

                continue;
            }

            file_put_contents($outPath, $response->body());
            $span->success("audio written: {$outPath} (" . strlen($response->body()) . ' bytes)');

            return;
        }

        throw new RuntimeException('9Router TTS failed on every configured model: ' . implode(' | ', $failures));
    }
}
