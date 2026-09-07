<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scoring
    |--------------------------------------------------------------------------
    |
    | Weights for ContentIdea::priority_score. Overridable per channel
    | (content_channels.scoring_weights) — see ContentChannel::effectiveScoringWeights().
    | They do not have to sum to 1: TopicScorer normalizes by the total weight, so
    | tweaking one weight doesn't silently rescale every score.
    |
    */
    'scoring' => [
        'weights' => [
            'trend' => (float) env('RESEARCH_WEIGHT_TREND', 0.30),
            'relevance' => (float) env('RESEARCH_WEIGHT_RELEVANCE', 0.30),
            'originality' => (float) env('RESEARCH_WEIGHT_ORIGINALITY', 0.15),
            'freshness' => (float) env('RESEARCH_WEIGHT_FRESHNESS', 0.10),
            'engagement' => (float) env('RESEARCH_WEIGHT_ENGAGEMENT', 0.10),
            'cross_source' => (float) env('RESEARCH_WEIGHT_CROSS_SOURCE', 0.05),
        ],

        // A topic seen in this many independent sources scores 100 for cross_source.
        'cross_source_saturation' => (int) env('RESEARCH_CROSS_SOURCE_SATURATION', 4),

        // Hours after which a result's freshness_score has decayed to 0.
        'freshness_window_hours' => (int) env('RESEARCH_FRESHNESS_WINDOW_HOURS', 72),
    ],

    /*
    |--------------------------------------------------------------------------
    | Topic extraction & duplicate detection
    |--------------------------------------------------------------------------
    */
    'topics' => [
        // Jaccard similarity above which two result titles are treated as the same topic.
        'cluster_threshold' => (float) env('RESEARCH_CLUSTER_THRESHOLD', 0.34),
        // Max topics handed to the AI idea generator per run — caps prompt size.
        'max_per_run' => (int) env('RESEARCH_MAX_TOPICS_PER_RUN', 25),
        'min_token_length' => 3,
    ],

    'duplicates' => [
        // Similarity above which a new idea counts as already covered. Compared against
        // the channel's own existing ideas only — the same topic across two channels is
        // expected and allowed (spec section 20).
        'similarity_threshold' => (float) env('RESEARCH_DUPLICATE_THRESHOLD', 0.62),
        // How far back to compare. Older ideas stop blocking new ones, since a topic can
        // legitimately come back around.
        'lookback_days' => (int) env('RESEARCH_DUPLICATE_LOOKBACK_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Shared by every provider extending AbstractHttpResearchProvider.
    |
    */
    'http' => [
        'timeout' => (int) env('RESEARCH_HTTP_TIMEOUT', 20),
        'retries' => (int) env('RESEARCH_HTTP_RETRIES', 2),
        'retry_base_ms' => (int) env('RESEARCH_HTTP_RETRY_BASE_MS', 400),
        // Providers cache identical queries for this long. A daily scheduler barely
        // benefits, but repeated manual "Run now" clicks while tuning a channel do —
        // and it keeps free/unauthenticated endpoints (Reddit, HN) well inside their
        // rate limits.
        'cache_ttl' => (int) env('RESEARCH_CACHE_TTL', 900),
        'user_agent' => env('RESEARCH_USER_AGENT', 'ClipperToolResearchBot/1.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-provider credentials
    |--------------------------------------------------------------------------
    |
    | Credentials live here (env-backed) and are NEVER stored in research_sources
    | rows or returned by the API — see spec section 35. A provider with missing
    | credentials reports itself unconfigured instead of failing mid-run.
    |
    */
    'providers' => [
        'youtube' => [
            // Shared with the existing trending feature rather than a second key.
            'api_key' => env('YOUTUBE_API_KEY'),
        ],
        'tmdb' => [
            'api_key' => env('TMDB_API_KEY'),
        ],
        'github' => [
            // Optional: raises the public search rate limit from 10 to 30 req/min.
            'token' => env('GITHUB_TOKEN'),
        ],
        'twitch' => [
            'client_id' => env('TWITCH_CLIENT_ID'),
            'client_secret' => env('TWITCH_CLIENT_SECRET'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Idea generation
    |--------------------------------------------------------------------------
    */
    'ideas' => [
        'default_per_run' => (int) env('RESEARCH_IDEAS_PER_RUN', 5),
        'max_per_run' => (int) env('RESEARCH_MAX_IDEAS_PER_RUN', 20),
        // How many previous idea titles are shown to the AI as "already covered".
        'previous_content_sample' => 40,
    ],

];
