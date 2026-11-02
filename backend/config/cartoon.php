<?php

return [
    // Hard cap on scenes per video. Every scene costs an image, a TTS call and (with an
    // AI scene_video provider) a paid video generation, so this is the cost ceiling.
    'max_scenes' => (int) env('CARTOON_MAX_SCENES', 10),

    // Silence held after each scene's narration, so scenes breathe instead of the next
    // sentence starting the instant the last one ends.
    'scene_tail_seconds' => (float) env('CARTOON_SCENE_TAIL_SECONDS', 0.35),

    // Rough per-scene prices (USD) used only for the cost estimate shown before rendering;
    // nothing is billed from these numbers. Image models routed through Antigravity are
    // free, grok-imagine-video is the paid part.
    'estimate' => [
        'image' => (float) env('CARTOON_ESTIMATE_IMAGE_USD', 0.0),
        'video_per_scene' => (float) env('CARTOON_ESTIMATE_VIDEO_SCENE_USD', 0.35),
    ],
];
