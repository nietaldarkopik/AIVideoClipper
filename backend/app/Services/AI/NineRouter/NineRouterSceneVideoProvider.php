<?php

namespace App\Services\AI\NineRouter;

use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Contracts\SceneVideoProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Image-to-video via a self-hosted 9Router instance's /v1/videos/generations
 * (e.g. xai/grok-imagine-video). The scene's cartoon image is the starting frame.
 *
 * The create call is async: it returns {"request_id"} plus an x-9router-connection-id
 * header, and the job must be polled at GET /v1/videos/{id} with that same connection id
 * (the job is bound to the upstream account that created it). A synchronous
 * {"data":[{"url"}]} response is also accepted. The create POST is never retried —
 * 9Router itself refuses to, because a retry would bill the generation twice.
 */
class NineRouterSceneVideoProvider implements SceneVideoProvider
{
    use LogsAiRequests;

    private const POLL_INTERVAL_SECONDS = 5;

    private const MAX_DURATION_SECONDS = 15;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly string $model = '',
        private readonly string $resolution = '720p',
        private readonly int $pollTimeoutSeconds = 600,
    ) {
    }

    public function animate(string $imagePath, string $motionPrompt, string $outPath, float $targetSeconds): void
    {
        if (empty($this->model)) {
            throw new RuntimeException('NINE_ROUTER_VIDEO_MODEL is not set (e.g. xai/grok-imagine-video).');
        }

        $image = file_get_contents($imagePath);
        if ($image === false || $image === '') {
            throw new RuntimeException("Scene image is missing or empty: {$imagePath}");
        }

        $dir = dirname($outPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $mime = str_ends_with(strtolower($imagePath), '.png') ? 'image/png' : 'image/jpeg';
        $prompt = trim($motionPrompt . ' Keep the flat 2D cartoon style exactly as in the image. No text, no subtitles.');
        $span = $this->aiLogger()->start('scene_video', 'nine_router', $this->model, $prompt);

        try {
            $create = $this->http(60)->post(rtrim($this->baseUrl, '/') . '/videos/generations', [
                'model' => $this->model,
                'prompt' => $prompt,
                'image' => ['url' => "data:{$mime};base64," . base64_encode($image)],
                'duration' => (int) min(self::MAX_DURATION_SECONDS, max(1, ceil($targetSeconds))),
                'aspect_ratio' => '9:16',
                'resolution' => $this->resolution,
            ]);

            if ($create->failed()) {
                throw new RuntimeException('9Router video generation failed (HTTP ' . $create->status() . '): ' . $create->body());
            }

            $url = $create->json('data.0.url') ?? $create->json('video.url');

            if (! $url) {
                $requestId = (string) $create->json('request_id');
                if ($requestId === '') {
                    throw new RuntimeException('9Router video response had neither a url nor a request_id: ' . mb_substr($create->body(), 0, 400));
                }

                $url = $this->pollForUrl($requestId, (string) $create->header('x-9router-connection-id'));
            }

            $bytes = $this->download($url);
        } catch (\Throwable $e) {
            $span->failure($e->getMessage());

            throw $e;
        }

        file_put_contents($outPath, $bytes);
        $span->success("video written: {$outPath} (" . strlen($bytes) . ' bytes)');
    }

    private function pollForUrl(string $requestId, string $connectionId): string
    {
        $deadline = time() + $this->pollTimeoutSeconds;

        while (time() < $deadline) {
            sleep(self::POLL_INTERVAL_SECONDS);

            $request = $this->http(30);
            if ($connectionId !== '') {
                $request = $request->withHeaders(['x-connection-id' => $connectionId]);
            }

            $response = $request->get(rtrim($this->baseUrl, '/') . '/videos/' . $requestId);

            if ($response->failed()) {
                throw new RuntimeException('9Router video poll failed (HTTP ' . $response->status() . '): ' . $response->body());
            }

            $status = (string) $response->json('status');

            if ($status === 'done' && ($url = $response->json('video.url'))) {
                return $url;
            }

            if ($status === 'failed') {
                throw new RuntimeException('9Router video generation failed: ' . ($response->json('error.message') ?? $response->body()));
            }
        }

        throw new RuntimeException("9Router video generation timed out after {$this->pollTimeoutSeconds}s (request {$requestId}).");
    }

    private function download(string $url): string
    {
        // The result is usually a public CDN URL; only fall back to sending the 9Router
        // token when it points back at the router itself.
        $response = $this->http(300, authenticated: str_starts_with($url, rtrim($this->baseUrl, '/')) || str_contains($url, 'localhost'))->get($url);

        if ($response->failed() || $response->body() === '') {
            throw new RuntimeException('Downloading the generated video failed (HTTP ' . $response->status() . ').');
        }

        return $response->body();
    }

    private function http(int $timeout, bool $authenticated = true): PendingRequest
    {
        $request = Http::timeout($timeout)->withOptions(['version' => 1.1]);

        return $authenticated && $this->apiKey ? $request->withToken($this->apiKey) : $request;
    }
}
