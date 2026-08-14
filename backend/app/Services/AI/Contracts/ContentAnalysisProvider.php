<?php

namespace App\Services\AI\Contracts;

use App\Models\Transcript;
use App\Services\AI\DTOs\SceneMarker;

interface ContentAnalysisProvider
{
    /**
     * Detect visual scene changes across the video.
     *
     * @return SceneMarker[]
     */
    public function detectScenes(string $videoPath, float $durationSeconds): array;

    /**
     * Analyze the transcript + scene markers and produce ranked clip candidates.
     *
     * @param  SceneMarker[]  $scenes
     * @return \App\Services\AI\DTOs\ClipCandidateData[]
     */
    public function analyzeMoments(Transcript $transcript, array $scenes, float $durationSeconds): array;
}
