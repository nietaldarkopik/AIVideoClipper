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
    ],

    'ai' => [
        'transcription_provider' => env('AI_TRANSCRIPTION_PROVIDER', 'mock'),
        'analysis_provider' => env('AI_ANALYSIS_PROVIDER', 'mock'),
        'reframing_provider' => env('AI_REFRAMING_PROVIDER', 'mock'),
        'social_metadata_provider' => env('AI_SOCIAL_METADATA_PROVIDER', 'mock'),
        'max_clips_per_video' => env('MAX_CLIPS_PER_VIDEO', 10),
        'default_clip_duration' => env('DEFAULT_CLIP_DURATION', 30),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'transcribe_model' => env('OPENAI_TRANSCRIBE_MODEL', 'whisper-1'),
        'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
    ],

    'whisper_engine' => [
        'base_url' => env('WHISPER_ENGINE_URL', 'http://127.0.0.1:8100'),
        // CPU-only inference on a long video can legitimately take a long time
        // (a ~65min clip took ~15-20min on a 4-core i7). No per-request cost like
        // OpenAI, so it's safe to give this a generous ceiling.
        'timeout' => env('WHISPER_ENGINE_TIMEOUT', 3600),
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

];
