<?php

namespace App\Services\AI\OpenAI;

use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Contracts\TextToSpeechProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAITextToSpeechProvider implements TextToSpeechProvider
{
    use LogsAiRequests;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'tts-1',
        private readonly string $defaultVoice = 'alloy',
    ) {
    }

    public function synthesize(string $text, string $outPath, ?string $voice = null): void
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY is not set — required for AI_TTS_PROVIDER=openai.');
        }

        $dir = dirname($outPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $resolvedVoice = $voice ?: $this->defaultVoice;
        $span = $this->aiLogger()->start('tts', 'openai', $this->model, "[{$resolvedVoice}] {$text}");

        $response = Http::withToken($this->apiKey)
            ->timeout(120)
            ->retry(2, 1500)
            ->withOptions(['version' => 1.1])
            ->post('https://api.openai.com/v1/audio/speech', [
                'model' => $this->model,
                'input' => $text,
                'voice' => $resolvedVoice,
                'response_format' => 'mp3',
            ]);

        if ($response->failed()) {
            $span->failure($response->body());

            throw new RuntimeException('OpenAI TTS failed: ' . $response->body());
        }

        file_put_contents($outPath, $response->body());
        $span->success("audio written: {$outPath} (" . strlen($response->body()) . ' bytes)');
    }
}
