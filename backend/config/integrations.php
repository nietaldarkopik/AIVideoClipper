<?php

/**
 * Limits for the endpoints meant to be called by OTHER applications with an API token
 * (POST /api/tts, POST /api/clips/import). They cost money or disk, and an integration
 * token can call them in a loop, so each has an explicit ceiling.
 */
return [
    'tts' => [
        // Characters per request — also bounds what one call can spend at the TTS provider.
        'max_chars' => (int) env('TTS_MAX_CHARS', 4000),

        // Generated audio is a throwaway artifact of an API call, not part of a project, so
        // it is pruned (see `tts:prune`) instead of piling up forever.
        'retention_days' => (int) env('TTS_RETENTION_DAYS', 7),
    ],

    // Host-name suffixes (beyond loopback/LAN addresses) that signed media URLs may follow, e.g. the
    // throw-away tunnel hosts used as a fallback when the main domain is down:
    //   MEDIA_TRUSTED_HOST_SUFFIXES=.trycloudflare.com,.ts.net
    // Empty by default: honouring arbitrary Host headers would let a caller steer our links elsewhere.
    'media_trusted_host_suffixes' => array_values(array_filter(array_map('trim', explode(',', (string) env('MEDIA_TRUSTED_HOST_SUFFIXES', ''))))),

    'clip_import' => [
        'max_mb' => (int) env('CLIP_IMPORT_MAX_MB', 2048),
        'max_duration_seconds' => (int) env('CLIP_IMPORT_MAX_DURATION_SECONDS', 10800),
    ],
];
