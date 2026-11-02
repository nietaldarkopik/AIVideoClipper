<?php

namespace App\Services\AI\Gemini;

use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Contracts\ImageGenerationProvider;

/**
 * Image generation through Gemini's Interactions API. Like the 9Router provider it
 * ignores $fallbackFramePath and always generates from $prompt.
 */
class GeminiImageGenerationProvider implements ImageGenerationProvider
{
    use LogsAiRequests;

    public function __construct(
        private readonly GeminiInteractionsClient $client,
        private readonly string $model = 'gemini-nano-banana-2.1',
    ) {
    }

    public function generateCoverImage(string $prompt, string $outputPath, ?string $fallbackFramePath = null): void
    {
        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $span = $this->aiLogger()->start('cover_image', 'gemini', $this->model, $prompt);

        try {
            $response = $this->client->create([
                'model' => $this->model,
                'input' => $prompt,
                'response_format' => [
                    'type' => 'image',
                    'mime_type' => 'image/jpeg',
                    'aspect_ratio' => '9:16',
                    'image_size' => '1K',
                ],
            ]);

            $bytes = $this->client->extractMedia($response, 'image');
        } catch (\Throwable $e) {
            $span->failure($e->getMessage());

            throw $e;
        }

        file_put_contents($outputPath, $bytes);
        $span->success("image written: {$outputPath} (" . strlen($bytes) . ' bytes)');
    }
}
