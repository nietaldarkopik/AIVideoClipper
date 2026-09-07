<?php

namespace App\Services\AI\Ollama;

use App\Exceptions\JobCancelledException;
use App\Models\Transcript;
use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\AI\Concerns\ParsesJsonResponses;
use App\Services\AI\Contracts\ContentAnalysisProvider;
use App\Services\AI\DTOs\ClipCandidateData;
use App\Services\AI\DTOs\SceneMarker;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Moment-detection via an Ollama instance (self-hosted or a shared server such as
 * prof.unwim.ac.id), using Ollama's native /api/chat endpoint. Same prompt contract
 * as OpenAIContentAnalysisProvider so behavior is a drop-in swap — free, but quality
 * and latency depend entirely on which model is loaded server-side and how busy it is.
 *
 * The transcript is analyzed in fixed time windows rather than as one giant prompt.
 * A ~30-minute video sent whole to a small shared model either times out (observed:
 * a 10-minute cURL timeout on video 9) or comes back with an empty candidate list —
 * the same way a person can't productively skim an hour of transcript in one pass.
 * Chunking keeps each request small and fast, and one chunk's failure only costs
 * that window's candidates instead of the whole analysis.
 */
class OllamaContentAnalysisProvider implements ContentAnalysisProvider
{
    use LogsAiRequests;
    use ParsesJsonResponses;

    private const CHUNK_SECONDS = 300.0;

    private const MAX_CANDIDATES_PER_CHUNK = 2;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds = 180,
    ) {}

    public function detectScenes(string $videoPath, float $durationSeconds): array
    {
        $scenes = [];
        for ($t = 8.0; $t < $durationSeconds; $t += 10.0) {
            $scenes[] = new SceneMarker(round($t, 2));
        }

        return $scenes;
    }

    public function analyzeMoments(Transcript $transcript, array $scenes, float $durationSeconds, ?Closure $shouldAbort = null): array
    {
        $segments = $transcript->segments ?? [];
        if (empty($segments)) {
            return [];
        }

        $results = [];
        foreach ($this->chunkSegments($segments) as $chunk) {
            if ($shouldAbort && $shouldAbort()) {
                throw new JobCancelledException('Cancelled by user.');
            }

            $results = array_merge($results, $this->analyzeChunk($chunk, $durationSeconds, $transcript->language));
        }

        usort($results, fn ($a, $b) => $b->overallScore <=> $a->overallScore);

        $maxCandidates = min(8, max(3, (int) round($durationSeconds / 180)));

        return array_slice($results, 0, $maxCandidates);
    }

    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $segments
     * @return array<int, array<int, array{start: float, end: float, text: string}>>
     */
    private function chunkSegments(array $segments): array
    {
        $chunks = [];
        foreach ($segments as $segment) {
            $index = (int) floor(((float) $segment['start']) / self::CHUNK_SECONDS);
            $chunks[$index][] = $segment;
        }

        ksort($chunks);

        return array_values($chunks);
    }

    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $chunk
     * @return ClipCandidateData[]
     */
    private function analyzeChunk(array $chunk, float $durationSeconds, string $language): array
    {
        $transcriptText = implode("\n", array_map(
            fn ($s) => sprintf('[%s-%s] %s', $this->fmt($s['start']), $this->fmt($s['end']), $s['text']),
            $chunk
        ));

        $chunkStart = (float) $chunk[0]['start'];
        $chunkEnd = (float) $chunk[count($chunk) - 1]['end'];

        $systemPrompt = $this->systemPrompt(self::MAX_CANDIDATES_PER_CHUNK);
        $userPrompt = $this->userPrompt($transcriptText, $durationSeconds, $chunkStart, $chunkEnd, $language);
        $span = $this->aiLogger()->start('content_analysis', 'ollama', $this->model, $systemPrompt."\n\n".$userPrompt);

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->post(rtrim($this->baseUrl, '/').'/api/chat', [
                    'model' => $this->model,
                    'stream' => false,
                    'format' => 'json',
                    'options' => ['temperature' => 0.4],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ]);
        } catch (ConnectionException|RequestException $e) {
            Log::warning('Ollama analysis chunk failed; skipping this window', [
                'chunk_start' => $chunk[0]['start'] ?? null,
                'error' => $e->getMessage(),
            ]);
            $span->failure($e->getMessage());

            return [];
        }

        if ($response->failed()) {
            Log::warning('Ollama analysis chunk returned an error; skipping this window', [
                'chunk_start' => $chunk[0]['start'] ?? null,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            $span->failure("HTTP {$response->status()}: {$response->body()}");

            return [];
        }

        $raw = $this->extractMessageContent($response->body());
        $span->success($raw);
        $parsed = $this->extractJsonObject($raw);
        $candidates = $parsed['candidates'] ?? [];

        if (! is_array($candidates)) {
            Log::warning('Ollama analysis chunk returned unexpected shape', [
                'chunk_start' => $chunk[0]['start'] ?? null,
                'raw' => $raw,
            ]);

            return [];
        }

        $results = [];
        foreach ($candidates as $c) {
            $start = (float) ($c['start_time'] ?? 0);
            $end = (float) ($c['end_time'] ?? 0);
            if ($end <= $start || $start < $chunkStart - 1 || $end > min($chunkEnd, $durationSeconds) + 1 || ($end - $start) < 8) {
                continue;
            }

            $results[] = new ClipCandidateData(
                startTime: round($start, 2),
                endTime: round(min($end, $durationSeconds), 2),
                overallScore: $this->clampScore($c['overall_score'] ?? 75),
                engagementScore: $this->clampScore($c['engagement_score'] ?? 75),
                hookScore: $this->clampScore($c['hook_score'] ?? 75),
                storyScore: $this->clampScore($c['story_score'] ?? 75),
                emotionalScore: $this->clampScore($c['emotional_score'] ?? 75),
                informationScore: $this->clampScore($c['information_score'] ?? 75),
                viralPotential: $this->clampScore($c['viral_potential'] ?? 75),
                hookText: (string) ($c['hook_text'] ?? ''),
                momentType: (string) ($c['moment_type'] ?? 'hook'),
                reasons: array_values(array_filter((array) ($c['reasons'] ?? []), 'is_string')),
                explanation: (string) ($c['explanation'] ?? ''),
                suggestedTitle: (string) ($c['suggested_title'] ?? ''),
                suggestedCaption: (string) ($c['suggested_caption'] ?? ''),
                hashtags: array_values(array_filter((array) ($c['hashtags'] ?? []), 'is_string')),
                coverTitles: ClipCandidateData::normalizeCoverStrings($c['cover_titles'] ?? [], 42),
                coverSubtitles: ClipCandidateData::normalizeCoverStrings($c['cover_subtitles'] ?? [], 18),
            );
        }

        return $results;
    }

    private function systemPrompt(int $maxCandidates): string
    {
        $coverInstructions = ClipCandidateData::coverPromptInstructions();

        return <<<PROMPT
You are an expert short-form video producer. Given a timestamped transcript excerpt
from a longer video, find up to {$maxCandidates} best self-contained moments in THIS
excerpt to cut into short vertical clips (like TikTok/Reels/Shorts). It's fine to
return fewer than {$maxCandidates}, or none, if this excerpt has no strong moments.

Respond with ONLY a JSON object: {"candidates": [...]}. Each candidate object must have:
start_time (number, seconds), end_time (number, seconds, 15-90 seconds after start_time
unless the moment genuinely needs longer), overall_score (1-100), engagement_score (1-100),
hook_score (1-100), story_score (1-100), emotional_score (1-100), information_score (1-100),
viral_potential (1-100), hook_text (the exact or near-exact opening line, in the transcript's
own language), moment_type (one of: hook, question, emotional, funny, controversial,
educational, story_peak, conclusion), reasons (array of 2-4 short strings explaining why this
clip works), explanation (one sentence summary), suggested_title (short, punchy, in the
transcript's own language), suggested_caption (1-2 sentences, in the transcript's own
language), hashtags (array of 4-6 relevant hashtag strings, no spaces).

{$coverInstructions}

Critical: hook_text, suggested_title, suggested_caption, and reasons/explanation must be
written in the SAME LANGUAGE as the transcript — never translate to English unless the
transcript itself is in English. Prefer moments with a strong opening line, a clear
self-contained idea, and an emotional or informational payoff. Only use start_time/end_time
values that fall within THIS EXCERPT's timestamp range, not the full video.
PROMPT;
    }

    private function userPrompt(string $transcriptText, float $durationSeconds, float $chunkStart, float $chunkEnd, string $language): string
    {
        return "This is one excerpt ({$this->fmt($chunkStart)}-{$this->fmt($chunkEnd)}) from a ".
            "{$durationSeconds}-second video. Transcript language: {$language}.\n\n".
            "Excerpt transcript:\n{$transcriptText}";
    }

    /**
     * We always send stream:false, but some proxies/servers stream regardless —
     * in that case the body is newline-delimited JSON chunks (Ollama's native
     * streaming format) rather than one JSON object. Handle both.
     */
    private function extractMessageContent(string $body): string
    {
        $single = json_decode($body, true);
        if (is_array($single) && isset($single['message']['content'])) {
            return (string) $single['message']['content'];
        }

        $content = '';
        foreach (explode("\n", trim($body)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $chunk = json_decode($line, true);
            $content .= (string) ($chunk['message']['content'] ?? '');
        }

        return $content;
    }

    private function clampScore(mixed $value): int
    {
        return max(1, min(100, (int) round((float) $value)));
    }

    private function fmt(float $seconds): string
    {
        return sprintf('%d:%02d', intdiv((int) $seconds, 60), ((int) $seconds) % 60);
    }
}
