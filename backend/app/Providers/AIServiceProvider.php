<?php

namespace App\Providers;

use App\Models\Setting;
use App\Services\AI\Claude\ClaudeContentAnalysisProvider;
use App\Services\AI\Contracts\AssistantChatProvider;
use App\Services\AI\Contracts\ContentAnalysisProvider;
use App\Services\AI\Contracts\ContentIdeaProvider;
use App\Services\AI\Contracts\EmbeddingProvider;
use App\Services\AI\Contracts\ImageGenerationProvider;
use App\Services\AI\Contracts\ReactionScriptProvider;
use App\Services\AI\Contracts\ReframingProvider;
use App\Services\AI\Contracts\SceneVideoProvider;
use App\Services\AI\Contracts\SocialMetadataProvider;
use App\Services\AI\Contracts\StoryboardProvider;
use App\Services\AI\Contracts\TextToSpeechProvider;
use App\Services\AI\Contracts\TranscriptionProvider;
use App\Services\AI\Contracts\VideoNarrativeProvider;
use App\Services\AI\Contracts\WebFetchProvider;
use App\Services\AI\Contracts\WebSearchProvider;
use App\Services\AI\FaceTracker\FaceTrackerReframingProvider;
use App\Services\AI\Gemini\GeminiContentAnalysisProvider;
use App\Services\AI\Gemini\GeminiContentIdeaProvider;
use App\Services\AI\Gemini\GeminiImageGenerationProvider;
use App\Services\AI\Gemini\GeminiInteractionsClient;
use App\Services\AI\Gemini\GeminiOmniSceneVideoProvider;
use App\Services\AI\Gemini\GeminiReactionScriptProvider;
use App\Services\AI\Groq\GroqTranscriptionProvider;
use App\Services\AI\Mock\MockAssistantChatProvider;
use App\Services\AI\Mock\MockContentAnalysisProvider;
use App\Services\AI\Mock\MockContentIdeaProvider;
use App\Services\AI\Mock\MockEmbeddingProvider;
use App\Services\AI\Mock\MockImageGenerationProvider;
use App\Services\AI\Mock\MockReactionScriptProvider;
use App\Services\AI\Mock\MockReframingProvider;
use App\Services\AI\Mock\MockSocialMetadataProvider;
use App\Services\AI\Mock\MockStoryboardProvider;
use App\Services\AI\Mock\MockTextToSpeechProvider;
use App\Services\AI\Mock\MockTranscriptionProvider;
use App\Services\AI\Mock\MockVideoNarrativeProvider;
use App\Services\AI\Mock\MockWebFetchProvider;
use App\Services\AI\Mock\MockWebSearchProvider;
use App\Services\AI\NineRouter\NineRouterAssistantChatProvider;
use App\Services\AI\NineRouter\NineRouterContentAnalysisProvider;
use App\Services\AI\NineRouter\NineRouterContentIdeaProvider;
use App\Services\AI\NineRouter\NineRouterEmbeddingProvider;
use App\Services\AI\NineRouter\NineRouterImageGenerationProvider;
use App\Services\AI\NineRouter\NineRouterReactionScriptProvider;
use App\Services\AI\NineRouter\NineRouterSceneVideoProvider;
use App\Services\AI\NineRouter\NineRouterSocialMetadataProvider;
use App\Services\AI\NineRouter\NineRouterStoryboardProvider;
use App\Services\AI\NineRouter\NineRouterTextToSpeechProvider;
use App\Services\AI\NineRouter\NineRouterTranscriptionProvider;
use App\Services\AI\NineRouter\NineRouterVideoNarrativeProvider;
use App\Services\AI\NineRouter\NineRouterWebFetchProvider;
use App\Services\AI\NineRouter\NineRouterWebSearchProvider;
use App\Services\AI\Ollama\OllamaContentAnalysisProvider;
use App\Services\AI\Ollama\OllamaReactionScriptProvider;
use App\Services\AI\Ollama\OllamaSocialMetadataProvider;
use App\Services\AI\OpenAI\OpenAIContentAnalysisProvider;
use App\Services\AI\OpenAI\OpenAIContentIdeaProvider;
use App\Services\AI\OpenAI\OpenAIReactionScriptProvider;
use App\Services\AI\OpenAI\OpenAISocialMetadataProvider;
use App\Services\AI\OpenAI\OpenAITextToSpeechProvider;
use App\Services\AI\OpenAI\OpenAITranscriptionProvider;
use App\Services\AI\WhisperEngine\WhisperEngineTranscriptionProvider;
use App\Services\AI\Support\FallbackImageGenerationProvider;
use App\Services\AI\Support\FallbackSceneVideoProvider;
use App\Services\AI\Video\CameraMotionSceneVideoProvider;
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
                proxy: config('services.ytdlp.proxy'),
                maxHeight: (int) config('services.ytdlp.max_height', 1440),
                cookiesFromBrowser: config('services.ytdlp.cookies_from_browser'),
                processTimeout: (int) config('services.ytdlp.process_timeout', 3600),
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
                'groq' => new GroqTranscriptionProvider(
                    $app->make(FFmpegService::class),
                    (string) config('services.groq.api_key'),
                    (string) config('services.groq.transcribe_model', 'whisper-large-v3-turbo'),
                ),
                default => throw new InvalidArgumentException("Unknown AI_TRANSCRIPTION_PROVIDER [{$provider}]. Valid values: mock, openai, whisper_engine, nine_router, groq."),
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
                    (string) (config('services.nine_router.analysis_model') ?: config('services.nine_router.model')),
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
                    (string) config('services.media.ffmpeg_bin', 'ffmpeg'),
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
                'nine_router' => new NineRouterSocialMetadataProvider(
                    (string) config('services.nine_router.base_url', 'http://localhost:20128/v1'),
                    config('services.nine_router.api_key'),
                    (string) config('services.nine_router.model'),
                ),
                default => throw new InvalidArgumentException("Unknown AI_SOCIAL_METADATA_PROVIDER [{$provider}]. Valid values: mock, openai, ollama, nine_router."),
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

        $this->app->bind(EmbeddingProvider::class, function ($app) {
            // Env-only, like social_metadata_provider above — no admin-panel toggle.
            return match ($provider = config('services.ai.embedding_provider', 'mock')) {
                'mock' => $app->make(MockEmbeddingProvider::class),
                'nine_router' => new NineRouterEmbeddingProvider(
                    (string) config('services.nine_router.base_url', 'http://localhost:20128/v1'),
                    config('services.nine_router.api_key'),
                    (string) config('services.nine_router.embedding_model'),
                ),
                default => throw new InvalidArgumentException("Unknown AI_EMBEDDING_PROVIDER [{$provider}]. Valid values: mock, nine_router."),
            };
        });

        $this->app->bind(WebFetchProvider::class, function ($app) {
            return match ($provider = config('services.ai.web_fetch_provider', 'mock')) {
                'mock' => $app->make(MockWebFetchProvider::class),
                'nine_router' => new NineRouterWebFetchProvider(
                    (string) config('services.nine_router.base_url', 'http://localhost:20128/v1'),
                    config('services.nine_router.api_key'),
                    (string) config('services.nine_router.web_fetch_model'),
                ),
                default => throw new InvalidArgumentException("Unknown AI_WEB_FETCH_PROVIDER [{$provider}]. Valid values: mock, nine_router."),
            };
        });

        $this->app->bind(WebSearchProvider::class, function ($app) {
            return match ($provider = config('services.ai.web_search_provider', 'mock')) {
                'mock' => $app->make(MockWebSearchProvider::class),
                'nine_router' => new NineRouterWebSearchProvider(
                    (string) config('services.nine_router.base_url', 'http://localhost:20128/v1'),
                    config('services.nine_router.api_key'),
                    (string) config('services.nine_router.web_search_model'),
                ),
                default => throw new InvalidArgumentException("Unknown AI_WEB_SEARCH_PROVIDER [{$provider}]. Valid values: mock, nine_router."),
            };
        });

        $this->app->bind(ContentIdeaProvider::class, function ($app) {
            // DB override so an admin can switch the idea generator from the settings
            // panel without touching .env — same reasoning as the bindings above. The
            // research scheduler runs unattended, so being able to swap a failing
            // provider without a queue restart matters more here than elsewhere.
            $provider = Setting::get('content_idea_model', config('services.ai.content_idea_provider', 'mock'));

            return match ($provider) {
                'mock' => $app->make(MockContentIdeaProvider::class),
                'openai' => new OpenAIContentIdeaProvider(
                    (string) config('services.openai.api_key'),
                    (string) config('services.openai.chat_model', 'gpt-4o-mini'),
                ),
                'gemini' => new GeminiContentIdeaProvider(
                    (string) config('services.gemini.api_key'),
                    (string) config('services.gemini.model', 'gemini-2.5-flash'),
                ),
                'nine_router' => new NineRouterContentIdeaProvider(
                    (string) config('services.nine_router.base_url', 'http://localhost:20128/v1'),
                    config('services.nine_router.api_key'),
                    (string) (config('services.nine_router.content_idea_model') ?: config('services.nine_router.model')),
                ),
                default => throw new InvalidArgumentException("Unknown AI_CONTENT_IDEA_PROVIDER [{$provider}]. Valid values: mock, openai, gemini, nine_router."),
            };
        });

        $this->app->bind(VideoNarrativeProvider::class, function ($app) {
            return match ($provider = config('services.ai.video_narrative_provider', 'mock')) {
                'mock' => $app->make(MockVideoNarrativeProvider::class),
                'nine_router' => new NineRouterVideoNarrativeProvider(
                    (string) config('services.nine_router.base_url', 'http://localhost:20128/v1'),
                    config('services.nine_router.api_key'),
                    (string) (config('services.nine_router.video_narrative_model') ?: config('services.nine_router.model')),
                ),
                default => throw new InvalidArgumentException("Unknown AI_VIDEO_NARRATIVE_PROVIDER [{$provider}]. Valid values: mock, nine_router."),
            };
        });

        $this->app->bind(ImageGenerationProvider::class, function ($app) {
            return $this->makeImageProvider(
                $app,
                (string) config('services.ai.cover_image_provider', 'mock'),
                (string) config('services.nine_router.image_model'),
                'AI_COVER_IMAGE_PROVIDER',
            );
        });

        // Scene images for cartoon videos: same contract, but configured independently so
        // enabling real image generation here doesn't also change reaction-intro covers.
        $this->app->bind('cartoon.image', function ($app) {
            return $this->makeImageChain(
                $app,
                (string) (config('services.ai.cartoon_image_provider') ?: config('services.ai.cover_image_provider', 'mock')),
                (string) (config('services.nine_router.cartoon_image_model') ?: config('services.nine_router.image_model')),
                'AI_CARTOON_IMAGE_PROVIDER',
            );
        });
        $this->app->bind(AssistantChatProvider::class, function ($app) {
            return match ($provider = config('services.ai.assistant_provider', 'mock')) {
                'mock' => $app->make(MockAssistantChatProvider::class),
                'nine_router' => new NineRouterAssistantChatProvider(
                    (string) config('services.nine_router.base_url', 'http://localhost:20128/v1'),
                    config('services.nine_router.api_key'),
                    (string) (config('services.nine_router.assistant_model') ?: config('services.nine_router.model')),
                ),
                default => throw new InvalidArgumentException("Unknown AI_ASSISTANT_PROVIDER [{$provider}]. Valid values: mock, nine_router."),
            };
        });

        $this->app->bind(StoryboardProvider::class, function ($app) {
            return match ($provider = config('services.ai.storyboard_provider', 'mock')) {
                'mock' => $app->make(MockStoryboardProvider::class),
                'nine_router' => new NineRouterStoryboardProvider(
                    (string) config('services.nine_router.base_url', 'http://localhost:20128/v1'),
                    config('services.nine_router.api_key'),
                    (string) (config('services.nine_router.storyboard_model')
                        ?: config('services.nine_router.video_narrative_model')
                        ?: config('services.nine_router.model')),
                ),
                default => throw new InvalidArgumentException("Unknown AI_STORYBOARD_PROVIDER [{$provider}]. Valid values: mock, nine_router."),
            };
        });

        $this->app->bind(SceneVideoProvider::class, function () {
            $spec = (string) config('services.ai.scene_video_provider', 'camera_motion');
            $entries = $this->chainEntries($spec);

            if (count($entries) === 1 && ! str_contains($entries[0], ':')) {
                return $this->makeSceneVideoProvider($entries[0], null);
            }

            return new FallbackSceneVideoProvider(array_map(function (string $entry) {
                [$name, $model] = array_pad(explode(':', $entry, 2), 2, null);

                return ['label' => $entry, 'provider' => $this->makeSceneVideoProvider($name, $model)];
            }, $entries));
        });
    }

    /**
     * A provider setting is either one name ("nine_router") or an ordered, comma-separated
     * rollover chain of "name[:model]" entries ("nine_router:ag/gemini-3.1-flash-image,gemini").
     *
     * @return string[]
     */
    private function chainEntries(string $spec): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $spec))));
    }

    private function makeImageChain($app, string $spec, string $defaultNineRouterModel, string $envName): ImageGenerationProvider
    {
        $entries = $this->chainEntries($spec);

        if (count($entries) <= 1 && ! str_contains($spec, ':')) {
            return $this->makeImageProvider($app, $spec, $defaultNineRouterModel, $envName);
        }

        return new FallbackImageGenerationProvider(array_map(function (string $entry) use ($app, $defaultNineRouterModel, $envName) {
            [$name, $model] = array_pad(explode(':', $entry, 2), 2, null);

            return ['label' => $entry, 'provider' => $this->makeImageProvider($app, $name, $model ?: $defaultNineRouterModel, $envName)];
        }, $entries));
    }

    private function makeSceneVideoProvider(string $provider, ?string $model): SceneVideoProvider
    {
        return match ($provider) {
            'camera_motion' => new CameraMotionSceneVideoProvider(config('services.media.ffmpeg_bin', 'ffmpeg')),
            'nine_router' => new NineRouterSceneVideoProvider(
                (string) config('services.nine_router.base_url', 'http://localhost:20128/v1'),
                config('services.nine_router.api_key'),
                $model ?: (string) config('services.nine_router.video_model'),
                (string) config('services.nine_router.video_resolution', '720p'),
            ),
            'gemini_omni' => new GeminiOmniSceneVideoProvider(
                new GeminiInteractionsClient((string) config('services.gemini.api_key')),
                $model ?: (string) config('services.gemini.video_model', 'gemini-omni-1.1-flash'),
                (string) config('services.gemini.video_resolution', '720p'),
            ),
            default => throw new InvalidArgumentException("Unknown AI_SCENE_VIDEO_PROVIDER entry [{$provider}]. Valid values: camera_motion, nine_router, gemini_omni (comma-separate to roll over in order)."),
        };
    }
    private function makeImageProvider($app, string $provider, string $nineRouterModel, string $envName): ImageGenerationProvider
    {
        return match ($provider) {
            'mock' => $app->make(MockImageGenerationProvider::class),
            'nine_router' => new NineRouterImageGenerationProvider(
                (string) config('services.nine_router.base_url', 'http://localhost:20128/v1'),
                config('services.nine_router.api_key'),
                $nineRouterModel,
            ),
            'gemini' => new GeminiImageGenerationProvider(
                new GeminiInteractionsClient((string) config('services.gemini.api_key')),
                (string) config('services.gemini.image_model', 'gemini-nano-banana-2.1'),
            ),
            default => throw new InvalidArgumentException("Unknown {$envName} [{$provider}]. Valid values: mock, nine_router, gemini."),
        };
    }
}
