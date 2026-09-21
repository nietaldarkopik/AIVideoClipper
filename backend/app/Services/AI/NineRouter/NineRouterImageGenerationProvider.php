<?php

namespace App\Services\AI\NineRouter;

use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Contracts\ImageGenerationProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real AI-generated cover image via a self-hosted 9Router instance's
 * /v1/images/generations endpoint. Ignores $fallbackFramePath entirely — unlike
 * MockImageGenerationProvider, this always generates a fresh image from $prompt.
 */
class NineRouterImageGenerationProvider implements ImageGenerationProvider
{
    use LogsAiRequests;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly string $model = '',
    ) {
    }

    public function generateCoverImage(string $prompt, string $outputPath, ?string $fallbackFramePath = null): void
    {
        if (empty($this->model)) {
            throw new RuntimeException(
                'NINE_ROUTER_IMAGE_MODEL is not set. Check GET ' . rtrim($this->baseUrl, '/') .
                '/models/image for the model ids your 9Router instance actually has credentials for.'
            );
        }

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $span = $this->aiLogger()->start('cover_image', 'nine_router', $this->model, $prompt);

        $request = Http::timeout(120)
            ->retry(2, 1500)
            ->withOptions(['version' => 1.1]);

        if ($this->apiKey) {
            $request = $request->withToken($this->apiKey);
        }

        $response = $request->post(rtrim($this->baseUrl, '/') . '/images/generations?response_format=binary', [
            'model' => $this->model,
            'prompt' => $prompt,
            'size' => '1080x1920',
        ]);

        if ($response->failed()) {
            $span->failure($response->body());

            throw new RuntimeException('9Router cover image generation failed: ' . $response->body());
        }

        file_put_contents($outputPath, $response->body());
        $span->success("image written: {$outputPath} (" . strlen($response->body()) . ' bytes)');
    }
}
