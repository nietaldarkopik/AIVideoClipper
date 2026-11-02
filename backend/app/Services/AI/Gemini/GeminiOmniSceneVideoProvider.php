<?php

namespace App\Services\AI\Gemini;

use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Contracts\SceneVideoProvider;
use RuntimeException;

/**
 * Image-to-video through Gemini Omni: the scene's cartoon image is the starting frame
 * and the motion prompt describes what moves. The model decides the clip length, so
 * $targetSeconds is only a hint — CartoonVideoAssembler fits the result to narration.
 * Audio generation is asked off: the narration (TTS) is the only soundtrack.
 */
class GeminiOmniSceneVideoProvider implements SceneVideoProvider
{
    use LogsAiRequests;

    public function __construct(
        private readonly GeminiInteractionsClient $client,
        private readonly string $model = 'gemini-omni-1.1-flash',
        private readonly string $resolution = '720p',
    ) {
    }

    public function animate(string $imagePath, string $motionPrompt, string $outPath, float $targetSeconds): void
    {
        $image = file_get_contents($imagePath);
        if ($image === false || $image === '') {
            throw new RuntimeException("Scene image is missing or empty: {$imagePath}");
        }

        $dir = dirname($outPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $mime = str_ends_with(strtolower($imagePath), '.png') ? 'image/png' : 'image/jpeg';
        $prompt = trim($motionPrompt . ' Keep the flat 2D cartoon style exactly as in the image. No dialogue, no music, no on-screen text.');
        $span = $this->aiLogger()->start('scene_video', 'gemini', $this->model, $prompt);

        try {
            $response = $this->client->create([
                'model' => $this->model,
                'input' => [
                    ['type' => 'image', 'data' => base64_encode($image), 'mime_type' => $mime],
                    ['type' => 'text', 'text' => $prompt],
                ],
                'response_format' => [
                    'type' => 'video',
                    'aspect_ratio' => '9:16',
                    'resolution' => $this->resolution,
                ],
            ]);

            $bytes = $this->client->extractMedia($response, 'video');
        } catch (\Throwable $e) {
            $span->failure($e->getMessage());

            throw $e;
        }

        file_put_contents($outPath, $bytes);
        $span->success("video written: {$outPath} (" . strlen($bytes) . ' bytes)');
    }
}
