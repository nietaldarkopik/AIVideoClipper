<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    // Used by App\Console\Commands\ReapStalledProcessingJobs — a ProcessingJob stuck
    // at status=running with no progress update for this many minutes is assumed
    // to have died with its worker process (crash, PC restart) and gets marked failed.
    'processing' => [
        'stall_minutes' => env('PROCESSING_JOB_STALL_MINUTES', 20),
    ],

    'google' => [
        // OAuth client for real YouTube publishing (YouTubeProvider). Set up at
        // console.cloud.google.com: enable "YouTube Data API v3", create an OAuth
        // client ID (Web application), and register redirect_uri below verbatim
        // as an authorized redirect URI.
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
    ],

    'facebook' => [
        // Meta for Developers app (developers.facebook.com) with Facebook Login
        // configured. Meta's "App Domains" field rejects bare "localhost"/wildcard
        // DNS tricks in practice, so this goes through a real reverse-proxied
        // subdomain (clipper.opik.unwim.ac.id -> Apache on server.unwim.ac.id ->
        // WireGuard tunnel -> this machine's Laravel dev server). Register
        // redirect_uri below verbatim as a Valid OAuth Redirect URI, and
        // "opik.unwim.ac.id" as an App Domain in Settings > Basic.
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v21.0'),
        'redirect_uri' => env('FACEBOOK_REDIRECT_URI'),
    ],

    'instagram_automation' => [
        // Self-hosted Puppeteer service (tools/instagram-automation) — no official
        // API involved. Instagram publishing has no OAuth: InstagramProvider logs
        // in with a stored username/password instead. See that service's README.
        'base_url' => env('IG_AUTOMATION_URL', 'http://127.0.0.1:8300'),
        'timeout' => env('IG_AUTOMATION_TIMEOUT', 180),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'media' => [
        'ffmpeg_bin' => env('FFMPEG_BIN', 'ffmpeg'),
        'ffprobe_bin' => env('FFPROBE_BIN', 'ffprobe'),
        'ytdlp_bin' => env('YTDLP_BIN', 'yt-dlp'),
        // x264 preset for clip rendering. Visual quality is controlled by -crf (see
        // FFmpegService::renderClip), not by preset — preset only trades encode
        // time for file size. Default 'superfast' favors a CPU-only, no-GPU machine;
        // go slower (e.g. 'medium') only if you specifically want smaller files and
        // have CPU headroom to spare.
        'ffmpeg_preset' => env('FFMPEG_PRESET', 'superfast'),
        // Fallback font FILE for template text layers (LayerCompositionService's
        // drawtext filter). Bare font *names* (drawtext's font= option) need an
        // ffmpeg build with a working libfontconfig config, which isn't a safe
        // assumption cross-platform/cross-machine (observed on Windows: fontconfig
        // compiled in but "Cannot load default config file" at runtime, even though
        // the same build's libass — a separate, unrelated font-matching path used
        // for ASS caption burn-in — works fine via DirectWrite). Pointing at a real
        // .ttf/.otf sidesteps that entirely; a template layer can still override
        // per-layer via props.font_file.
        'default_font_file' => env('DEFAULT_FONT_FILE'),
    ],

    'trending' => [
        // Every non-YouTube platform has no viable free/ToS-safe trending API and
        // stays mock. YouTube defaults to mock too (zero-setup, like every other
        // provider in this app) — set youtube_provider=youtube_api and provide
        // youtube_api_key to switch on real trending data. Get a key at
        // console.cloud.google.com: enable "YouTube Data API v3" > Credentials >
        // Create Credentials > API key. This is separate from the OAuth client
        // (services.google.*) used for real YouTube publishing.
        'youtube_provider' => env('TRENDING_YOUTUBE_PROVIDER', 'mock'),
        'youtube_api_key' => env('YOUTUBE_API_KEY'),
        // TikTok/Instagram/Facebook/Twitter still have no official trending API, but
        // can approximate one via 9Router web search (domain-filtered to each
        // platform) — set to nine_router_search + configure
        // services.nine_router.web_search_model to switch one on. Best-effort: a
        // search result isn't guaranteed to be a single yt-dlp-downloadable post the
        // way the curated mock seed list is — see AbstractNineRouterSearchTrendingProvider.
        'tiktok_provider' => env('TRENDING_TIKTOK_PROVIDER', 'mock'),
        'instagram_provider' => env('TRENDING_INSTAGRAM_PROVIDER', 'mock'),
        'facebook_provider' => env('TRENDING_FACEBOOK_PROVIDER', 'mock'),
        'twitter_provider' => env('TRENDING_TWITTER_PROVIDER', 'mock'),
        'cache_ttl' => env('TRENDING_CACHE_TTL', 900),
        'region_code' => env('TRENDING_REGION_CODE', 'US'),
    ],

    'ai' => [
        'transcription_provider' => env('AI_TRANSCRIPTION_PROVIDER', 'mock'),
        'analysis_provider' => env('AI_ANALYSIS_PROVIDER', 'mock'),
        'reframing_provider' => env('AI_REFRAMING_PROVIDER', 'mock'),
        'social_metadata_provider' => env('AI_SOCIAL_METADATA_PROVIDER', 'mock'),
        // Reaction-intro cover feature: a short provocative one-liner reacting to
        // the clip's own content (positive/hype if good, satire if not), narrated
        // over a cover screen before the clip plays — see ReactionScriptProvider.
        'reaction_script_provider' => env('AI_REACTION_SCRIPT_PROVIDER', 'mock'),
        // Text-to-speech for that reaction line — see TextToSpeechProvider.
        'tts_provider' => env('AI_TTS_PROVIDER', 'mock'),
        // Vector embeddings for clip semantic search — see ClipSearchService.
        'embedding_provider' => env('AI_EMBEDDING_PROVIDER', 'mock'),
        // URL -> markdown/text fetch, used as extra context for reaction-script /
        // social-metadata generation — see WebContentFetcher.
        'web_fetch_provider' => env('AI_WEB_FETCH_PROVIDER', 'mock'),
        // Multi-result web search for the trending -> content-brief research
        // pipeline — see WebSearchProvider / GenerateContentBriefJob.
        'web_search_provider' => env('AI_WEB_SEARCH_PROVIDER', 'mock'),
        // Long-form Indonesian video narrative/script generation from gathered
        // research — see VideoNarrativeProvider / GenerateContentBriefJob.
        'video_narrative_provider' => env('AI_VIDEO_NARRATIVE_PROVIDER', 'mock'),
        // AI-generated background for the reaction intro cover — see
        // RenderClipJob::composeIntroOutro().
        'cover_image_provider' => env('AI_COVER_IMAGE_PROVIDER', 'mock'),
        'max_clips_per_video' => env('MAX_CLIPS_PER_VIDEO', 10),
        'default_clip_duration' => env('DEFAULT_CLIP_DURATION', 30),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'transcribe_model' => env('OPENAI_TRANSCRIBE_MODEL', 'whisper-1'),
        'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
        'tts_model' => env('OPENAI_TTS_MODEL', 'tts-1'),
        'tts_voice' => env('OPENAI_TTS_VOICE', 'alloy'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
    ],

    'gemini' => [
        // From Google AI Studio (https://aistudio.google.com/apikey) — separate from
        // and unrelated to any Google Cloud/Vertex project credentials.
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    'groq' => [
        // From console.groq.com/keys — free tier, OpenAI-compatible /audio/
        // transcriptions endpoint. Used for AI_TRANSCRIPTION_PROVIDER=groq, a
        // fallback when OpenAI's account runs out of credit and self-hosted
        // whisper_engine isn't an option (CPU load triggering reboots).
        'api_key' => env('GROQ_API_KEY'),
        // "whisper-large-v3-turbo" is faster/cheaper; "whisper-large-v3" is the
        // more accurate (slower) full model — swap if turbo's accuracy isn't enough.
        'transcribe_model' => env('GROQ_TRANSCRIBE_MODEL', 'whisper-large-v3-turbo'),
    ],

    'nine_router' => [
        // Self-hosted OpenAI-compatible LLM gateway (https://9router.com) — routes
        // each request across many upstream providers, picking the cheapest one
        // that meets quality. Default port matches 9Router's own default install.
        'base_url' => env('NINE_ROUTER_BASE_URL', 'http://localhost:20128/v1'),
        'api_key' => env('NINE_ROUTER_API_KEY'),
        // No safe default — every instance's upstream credentials differ. Check
        // GET {base_url}/models on your own instance for a valid id (e.g.
        // "cc/claude-sonnet-5"); "auto" is NOT a real model id and will 404.
        'model' => env('NINE_ROUTER_MODEL'),
        // Separate model id for the /audio/transcriptions endpoint — a chat model
        // id from services.nine_router.model will not work here. Check GET
        // {base_url}/models for a Whisper-compatible id your instance has
        // credentials for.
        'transcribe_model' => env('NINE_ROUTER_TRANSCRIBE_MODEL'),
        // Model id(s) for the /audio/speech (TTS) endpoint — same caveat as
        // transcribe_model above. Comma-separated to configure a fallback chain
        // (e.g. "openai/gpt-4o-mini-tts,gemini/gemini-3.1-flash-tts-preview/Zephyr")
        // — each upstream credential has its own separate quota, so
        // NineRouterTextToSpeechProvider tries the next one whenever one is
        // rate-limited/over quota, only giving up once all of them have failed.
        'tts_model' => env('NINE_ROUTER_TTS_MODEL'),
        // Separate model id for reaction-script generation (still a plain chat
        // completion, same endpoint as `model` above) — lets it target a different
        // registered provider/credential (e.g. a Gemini entry) than clip scoring
        // does. Falls back to `model` when unset — see AIServiceProvider.
        'reaction_script_model' => env('NINE_ROUTER_REACTION_SCRIPT_MODEL'),
        // Model id for the /embeddings endpoint — check GET {base_url}/models/embedding.
        'embedding_model' => env('NINE_ROUTER_EMBEDDING_MODEL'),
        // Model id for the /web/fetch endpoint (a provider name, e.g. "jina-reader") —
        // check GET {base_url}/models/web (kind=="webFetch").
        'web_fetch_model' => env('NINE_ROUTER_WEB_FETCH_MODEL'),
        // Model id for the /web/search endpoint (a provider name, e.g. "tavily") — used
        // by the trending non-YouTube platforms and by WebSearchProvider (content-brief
        // research), check GET {base_url}/models/web (kind=="webSearch"). See
        // TrendingServiceProvider and AIServiceProvider.
        'web_search_model' => env('NINE_ROUTER_WEB_SEARCH_MODEL'),
        // Separate model id for long-form video narrative generation (still a plain
        // chat completion, same endpoint as `model` above). Falls back to `model`
        // when unset — see AIServiceProvider.
        'video_narrative_model' => env('NINE_ROUTER_VIDEO_NARRATIVE_MODEL'),
        // Model id for the /images/generations endpoint — check GET {base_url}/models/image.
        'image_model' => env('NINE_ROUTER_IMAGE_MODEL'),
    ],

    'whisper_engine' => [
        'base_url' => env('WHISPER_ENGINE_URL', 'http://127.0.0.1:8100'),
        // CPU-only inference on a long video can legitimately take a long time
        // (a ~65min clip took ~15-20min on a 4-core i7). No per-request cost like
        // OpenAI, so it's safe to give this a generous ceiling.
        'timeout' => env('WHISPER_ENGINE_TIMEOUT', 3600),
        // Long recordings are split into WAV chunks of this length before each is
        // sent to the engine, so peak CPU/RAM per request stays bounded instead of
        // scaling with total video length — on weaker machines, transcribing an hour
        // of audio in one call has been enough to lock up or reboot the PC.
        'chunk_seconds' => env('WHISPER_ENGINE_CHUNK_SECONDS', 120),
    ],

    'ollama' => [
        // Self-hosted — point this at wherever the Ollama instance actually runs
        // (localhost, a LAN box, a separate server). No API key: Ollama has no auth
        // of its own, so treat the URL itself as sensitive if it's reachable from
        // outside a trusted network.
        'base_url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL', 'llama3.1'),
        'timeout' => env('OLLAMA_TIMEOUT', 180),
    ],

    'face_tracker' => [
        'base_url' => env('FACE_TRACKER_URL', 'http://127.0.0.1:8200'),
        'timeout' => env('FACE_TRACKER_TIMEOUT', 300),
    ],

    'ytdlp' => [
        // YouTube increasingly requires a Proof-of-Origin token for its default
        // "web" client, which yt-dlp can't obtain on its own — that shows up as a
        // plain HTTP 403 on an otherwise-valid, public video. The community-standard
        // workaround is forcing yt-dlp to impersonate a different client that
        // doesn't need one; which clients still work shifts over time as YouTube
        // patches this, so it's a comma-separated, tunable list rather than one
        // hardcoded value. Tried in order — UrlVideoDownloader moves to the next
        // client on a 403/blocked error instead of retrying the same one.
        'player_clients' => env('YTDLP_PLAYER_CLIENTS', 'android,tv,web'),
        // Routes every yt-dlp request (video + captions) through this proxy when
        // set — yt-dlp's own --proxy syntax, e.g. "http://user:pass@host:port" or
        // "socks5://host:port". Needed when a platform blocks this server's IP
        // outright (observed with TikTok — no yt-dlp version fixes that, only a
        // different egress IP does); leave unset to download directly as before.
        'proxy' => env('YTDLP_PROXY'),
    ],

    'video_batch' => [
        // Pause between the batch autobot's downloads (ProcessVideoBatchJob) — each
        // item still downloads strictly one at a time, but with processing now
        // overlapping the next download (see that job's docblock), downloads
        // themselves could otherwise fire back-to-back with zero gap. A small pause
        // keeps the request cadence looking less bot-like to the source platform.
        'download_delay_seconds' => env('BATCH_DOWNLOAD_DELAY_SECONDS', 10),
    ],

];
