<?php

namespace App\Services\AI\Support;

use App\Services\AI\Contracts\SceneVideoProvider;

/**
 * SceneVideoProvider that rolls over across providers — see ProviderChain. Put
 * camera_motion last and a scene can never fail for lack of a paid video model: it just
 * gets the free zoom/pan instead. lastUsed() says which one produced the clip, so the
 * UI can show which scenes got the cheaper fallback.
 */
class FallbackSceneVideoProvider implements SceneVideoProvider
{
    private readonly ProviderChain $chain;

    /**
     * @param  array<int, array{label: string, provider: SceneVideoProvider}>  $candidates
     */
    public function __construct(array $candidates)
    {
        $this->chain = new ProviderChain('video', $candidates);
    }

    public function animate(string $imagePath, string $motionPrompt, string $outPath, float $targetSeconds): void
    {
        $this->chain->run(function (SceneVideoProvider $p) use ($imagePath, $motionPrompt, $outPath, $targetSeconds) {
            // A failed attempt may have left a partial file behind; the next provider writes the same path.
            @unlink($outPath);
            $p->animate($imagePath, $motionPrompt, $outPath, $targetSeconds);
        });
    }

    public function lastUsed(): ?string
    {
        return $this->chain->lastUsed();
    }
}
