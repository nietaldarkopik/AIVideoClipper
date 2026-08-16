<?php

namespace App\Services\AI\Contracts;

use App\Models\Transcript;
use App\Services\AI\DTOs\SceneMarker;
use Closure;

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
     * $shouldAbort, if given, is checked between internal chunks by providers that
     * analyze the transcript in windows (see OllamaContentAnalysisProvider) so a
     * caller (AnalyzeVideoJob) can stop partway through instead of only between its
     * own top-level phases. Providers that make a single request may ignore it.
     * Returning true throws App\Exceptions\JobCancelledException.
     *
     * @param  SceneMarker[]  $scenes
     * @return \App\Services\AI\DTOs\ClipCandidateData[]
     */
    public function analyzeMoments(Transcript $transcript, array $scenes, float $durationSeconds, ?Closure $shouldAbort = null): array;
}
