<?php

namespace App\Providers;

use App\Models\Setting;
use App\Services\AI\Contracts\ContentAnalysisProvider;
use App\Services\AI\Contracts\ReframingProvider;
use App\Services\AI\Contracts\SocialMetadataProvider;
use App\Services\AI\Contracts\TranscriptionProvider;
use App\Services\AI\Mock\MockContentAnalysisProvider;
use App\Services\AI\Mock\MockReframingProvider;
use App\Services\AI\Mock\MockSocialMetadataProvider;
use App\Services\AI\Mock\MockTranscriptionProvider;
use App\Services\AI\Ollama\OllamaContentAnalysisProvider;
use App\Services\AI\Ollama\OllamaSocialMetadataProvider;
use App\Services\AI\OpenAI\OpenAIContentAnalysisProvider;
use App\Services\AI\OpenAI\OpenAISocialMetadataProvider;
use App\Services\AI\OpenAI\OpenAITranscriptionProvider;
use App\Services\AI\WhisperEngine\WhisperEngineTranscriptionProvider;
use App\Services\Video\FFmpegService;
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
                    (string) config('services.whisper_engine.base_url'),
                    (int) config('services.whisper_engine.timeout', 1200),
                ),
                default => throw new InvalidArgumentException("Unknown AI_TRANSCRIPTION_PROVIDER [{$provider}]. Valid values: mock, openai, whisper_engine."),
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
                default => throw new InvalidArgumentException("Unknown AI_ANALYSIS_PROVIDER [{$provider}]. Valid values: mock, openai, ollama."),
            };
        });

        $this->app->bind(ReframingProvider::class, function ($app) {
            return match ($provider = config('services.ai.reframing_provider', 'mock')) {
                'mock' => $app->make(MockReframingProvider::class),
                default => throw new InvalidArgumentException("Unknown AI_REFRAMING_PROVIDER [{$provider}]. Valid values: mock."),
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
    }
}
