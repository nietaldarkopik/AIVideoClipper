<?php

namespace App\Providers;

use App\Models\Setting;
use App\Services\AI\Claude\ClaudeContentAnalysisProvider;
use App\Services\AI\Contracts\ContentAnalysisProvider;
use App\Services\AI\Contracts\ReframingProvider;
use App\Services\AI\Contracts\SocialMetadataProvider;
use App\Services\AI\Contracts\TranscriptionProvider;
use App\Services\AI\FaceTracker\FaceTrackerReframingProvider;
use App\Services\AI\Gemini\GeminiContentAnalysisProvider;
use App\Services\AI\Gemini\GeminiReactionScriptProvider;
use App\Services\AI\Mock\MockContentAnalysisProvider;
use App\Services\AI\Mock\MockReframingProvider;
use App\Services\AI\Mock\MockSocialMetadataProvider;
use App\Services\AI\Mock\MockTranscriptionProvider;
use App\Services\AI\Contracts\ReactionScriptProvider;
use App\Services\AI\Contracts\TextToSpeechProvider;
use App\Services\AI\Mock\MockReactionScriptProvider;
use App\Services\AI\Mock\MockTextToSpeechProvider;
use App\Services\AI\NineRouter\NineRouterContentAnalysisProvider;
use App\Services\AI\NineRouter\NineRouterReactionScriptProvider;
use App\Services\AI\NineRouter\NineRouterTextToSpeechProvider;
use App\Services\AI\NineRouter\NineRouterTranscriptionProvider;
use App\Services\AI\Ollama\OllamaContentAnalysisProvider;
use App\Services\AI\Ollama\OllamaReactionScriptProvider;
use App\Services\AI\Ollama\OllamaSocialMetadataProvider;
use App\Services\AI\OpenAI\OpenAIContentAnalysisProvider;
use App\Services\AI\OpenAI\OpenAIReactionScriptProvider;
use App\Services\AI\OpenAI\OpenAISocialMetadataProvider;
use App\Services\AI\OpenAI\OpenAITextToSpeechProvider;
use App\Services\AI\OpenAI\OpenAITranscriptionProvider;
use App\Services\AI\WhisperEngine\WhisperEngineTranscriptionProvider;
use App\Services\Video\FFmpegService;
use App\Services\Video\LayerCompositionService;
use App\Services\Video\UrlVideoDownloader;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Binds each AI capability to a concrete provider chosen via config('services.ai.*').
 * Every capability defaults to "mock" so the whole pipeline runs with zero API keys.
 * Unrecognized provider names throw immediately instead of silently falling back to
 * mock — that silent-fallback behavior is exactly what caused a confusing "captions
 * are still English" bug when a provider name was mistyped.
 */
class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FFmpegService::class, function () {
            return new FFmpegService(
                ffmpegBin: config('services.media.ffmpeg_bin', 'ffmpeg'),
                ffprobeBin: config('services.media.ffprobe_bin', 'ffprobe'),
                x264Preset: config('services.media.ffmpeg_preset', 'superfast'),
                layerService: new LayerCompositionService(
                    defaultFontFile: config('services.media.default_font_file'),
                ),
                defaultFontFile: config('services.media.default_font_file'),
            );
        });

        $this->app->singleton(UrlVideoDownloader::class, function () {
            return new UrlVideoDownloader(
                ytDlpBin: config('services.media.ytdlp_bin', 'yt-dlp'),
                ffmpegBin: config('services.media.ffmpeg_bin', 'ffmpeg'),
            );
        });

        $this->app->bind(TranscriptionProvider::class, function ($app) {
            // Checked at request time (not cached in config) so an admin can flip
            // providers from the settings panel — e.g. fall back to the free
            // self-hosted whisper_engine if the OpenAI account runs out of credit —
            // without editing .env or restarting the queue worker.
            $provider = Setting::get('transcript_model', config('services.ai.transcription_provider', 'mock'));

            return match ($provider) {
                'mock' => $app->make(MockTranscriptionProvider::class),
                'openai' => new OpenAITranscriptionProvider(
                    $app->make(FFmpegService::class),
                    (string) config('services.openai.api_key'),
                    (string) config('services.openai.transcribe_model', 'whisper-1'),
                ),
                'whisper_engine' => new WhisperEngineTranscriptionProvider(
                    $app->make(FFmpegService::class),
                    (string) config('services.whisper_engine.base_url'),
                    (int) config('services.whisper_engine.timeout', 1200),
                    (float) config('services.whisper_engine.chunk_seconds', 120),
                ),
                'nine_router' => new NineRouterTranscriptionProvider(
                    $app->make(FFmpegService::class),
                    (string) config('services.nine_router.base_url', 'http://localhost:20128/v1'),
                    config('services.nine_router.api_key'),
                    (string) config('services.nine_router.transcribe_model'),
                ),
                default => throw new InvalidArgumentException("Unknown AI_TRANSCRIPTION_PROVIDER [{$provider}]. Valid values: mock, openai, whisper_engine, nine_router."),
            };
        });

        $this->app->bind(ContentAnalysisProvider::class, function ($app) {
            // DB override so an admin can switch away from a paid provider (e.g. when
            // OpenAI credit runs out) from the settings panel — see the matching
            // comment on the TranscriptionProvider binding above.
            $provider = Setting::get('clip_scoring_model', config('services.ai.analysis_provider', 'mock'));

            return match ($provider) {
                'mock' => $app->make(MockContentAnalysisProvider::class),
                'openai' => new OpenAIContentAnalysisProvider(
                    (string) config('services.openai.api_key'),
                    (string) config('services.openai.chat_model', 'gpt-4o-mini'),
                ),
                'ollama' => new OllamaContentAnalysisProvider(
                    (string) config('services.ollama.base_url'),
                    (string) config('services.ollama.model', 'llama3.1'),
                    (int) config('services.ollama.timeout', 180),
                ),
                'claude' => new ClaudeContentAnalysisProvider(
                    (string) config('services.anthropic.api_key'),
                    (string) config('services.anthropic.model', 'claude-sonnet-5'),
                ),
                'gemini' => new GeminiContentAnalysisProvider(
                    (string) config('services.gemini.api_key'),
                    (string) config('services.gemini.model', 'gemini-2.5-flash'),
                ),
                'nine_router' => new NineRouterContentAnalysisProvider(
                    (string) config('services.nine_router.base_url', 'http://localhost:20128/v1'),
                    config('services.nine_router.api_key'),
                    (string) config('services.nine_router.model'),
                ),
                default => throw new InvalidArgumentException("Unknown AI_ANALYSIS_PROVIDER [{$provider}]. Valid values: mock, openai, ollama, claude, gemini, nine_router."),
            };
        });

        $this->app->bind(ReframingProvider::class, function ($app) {
            return match ($provider = config('services.ai.reframing_provider', 'mock')) {
                'mock' => $app->make(MockReframingProvider::class),
                'face_tracker' => new FaceTrackerReframingProvider(
                    (string) config('services.face_tracker.base_url'),
                    (int) config('services.face_tracker.timeout', 300),
                ),
                default => throw new InvalidArgumentException("Unknown AI_REFRAMING_PROVIDER [{$provider}]. Valid values: mock, face_tracker."),
            };
        });

        $this->app->bind(SocialMetadataProvider::class, function ($app) {
            return match ($provider = config('services.ai.social_metadata_provider', 'mock')) {
                'mock' => $app->make(MockSocialMetadataProvider::class),
                'openai' => new OpenAISocialMetadataProvider(
                    (string) config('services.openai.api_key'),
                    (string) config('services.openai.chat_model', 'gpt-4o-mini'),
                ),
                'ollama' => new OllamaSocialMetadataProvider(
                    (string) config('services.ollama.base_url'),
                    (string) config('services.ollama.model', 'llama3.1'),
                    (int) config('services.ollama.timeout', 60),
                ),
                default => throw new InvalidArgumentException("Unknown AI_SOCIAL_METADATA_PROVIDER [{$provider}]. Valid values: mock, openai, ollama."),
            };
        });

        $this->app->bind(ReactionScriptProvider::class, function ($app) {
            $provider = Setting::get('reaction_script_model', config('services.ai.reaction_script_provider', 'mock'));

            return match ($provider) {
                'mock' => $app->make(MockReactionScriptProvider::class),
                'openai' => new OpenAIReactionScriptProvider(
                    (string) config('services.openai.api_key'),
                    (string) config('services.openai.chat_model', 'gpt-4o-mini'),
                ),
                'ollama' => new OllamaReactionScriptProvider(
                    (string) config('services.ollama.base_url'),
                    (string) config('services.ollama.model', 'llama3.1'),
                    (int) config('services.ollama.timeout', 60),
                ),
                'gemini' => new GeminiReactionScriptProvider(
                    (string) config('services.gemini.api_key'),
                    (string) config('services.gemini.model', 'gemini-2.5-flash'),
                ),
                'nine_router' => new NineRouterReactionScriptProvider(
                    (string) config('services.nine_router.base_url', 'http://localhost:20128/v1'),
                    config('services.nine_router.api_key'),
                    // A dedicated model id lets this differ from services.nine_router.model
                    // (used for clip scoring) — e.g. routing reaction scripts to a Gemini
                    // credential registered in 9Router while clip scoring stays on
                    // whatever chat model/router is configured for that. Falls back to
                    // the shared chat model when not explicitly set.
                    (string) (config('services.nine_router.reaction_script_model') ?: config('services.nine_router.model')),
                ),
                default => throw new InvalidArgumentException("Unknown AI_REACTION_SCRIPT_PROVIDER [{$provider}]. Valid values: mock, openai, ollama, gemini, nine_router."),
            };
        });

        $this->app->bind(TextToSpeechProvider::class, function ($app) {
            $provider = Setting::get('tts_model', config('services.ai.tts_provider', 'mock'));

            return match ($provider) {
                'mock' => new MockTextToSpeechProvider(
                    config('services.media.ffmpeg_bin', 'ffmpeg'),
                ),
                'openai' => new OpenAITextToSpeechProvider(
                    (string) config('services.openai.api_key'),
                    (string) config('services.openai.tts_model', 'tts-1'),
                    (string) config('services.openai.tts_voice', 'alloy'),
                ),
                'nine_router' => new NineRouterTextToSpeechProvider(
                    (string) config('services.nine_router.base_url', 'http://localhost:20128/v1'),
                    config('services.nine_router.api_key'),
                    // Comma-separated fallback chain (e.g. "openai/gpt-4o-mini-tts,
                    // gemini/gemini-3.1-flash-tts-preview/Zephyr") — each upstream
                    // credential has its own quota, so the next one is tried whenever
                    // one is rate-limited/over quota. See the provider's docblock.
                    array_values(array_filter(array_map('trim', explode(',', (string) config('services.nine_router.tts_model'))))),
                ),
                default => throw new InvalidArgumentException("Unknown AI_TTS_PROVIDER [{$provider}]. Valid values: mock, openai, nine_router."),
            };
        });
    }
}
