<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Super Admins
    |--------------------------------------------------------------------------
    |
    | Platform operators (comma-separated emails). They can open Horizon,
    | which shows jobs from every workspace.
    |
    */

    'super_admins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CURATION_SUPER_ADMINS', ''))))),

    /*
    |--------------------------------------------------------------------------
    | Workspace Defaults
    |--------------------------------------------------------------------------
    |
    | Every workspace (tenant) can override these in its own "settings"
    | column. Workspace::setting('ai.model') falls back to this array.
    |
    */

    'workspace_defaults' => [
        'ai' => [
            'provider' => env('CURATION_AI_PROVIDER', 'openai'),
            'model' => env('CURATION_AI_MODEL', 'gpt-5.4-nano'),
            // Cheap relevance-only pass before full enrichment (e.g. "gpt-5-nano"). Null disables it.
            'prefilter_model' => env('CURATION_AI_PREFILTER_MODEL'),
            'fallback_provider' => env('CURATION_AI_FALLBACK_PROVIDER', 'gemini'),
            'fallback_model' => env('CURATION_AI_FALLBACK_MODEL', 'gemini-3.1-flash-lite'),
            'web_search_model' => env('CURATION_AI_WEB_SEARCH_MODEL', 'gpt-5.4-nano'),
            'daily_budget_usd' => (float) env('CURATION_AI_DAILY_BUDGET_USD', 5),
        ],
        'timezone' => env('CURATION_TIMEZONE', 'Asia/Kolkata'),
        'region' => 'IN',
        'language' => 'en-IN',
    ],

    /*
    |--------------------------------------------------------------------------
    | AI
    |--------------------------------------------------------------------------
    */

    'ai' => [
        // Items scoring below this land in the "Low relevance" tab and skip full enrichment after a prefilter.
        'relevance_threshold' => 30,
        'max_content_chars' => 6000,
        'batch_size' => 10,

        // USD per 1M tokens, used for the estimated cost in ai_usage and the daily budget.
        'pricing' => [
            'gpt-5.4-nano' => ['input' => 0.20, 'output' => 1.25],
            'gpt-5-nano' => ['input' => 0.05, 'output' => 0.40],
            'gpt-5.4-mini' => ['input' => 0.75, 'output' => 4.50],
            'gemini-3.1-flash-lite' => ['input' => 0.25, 'output' => 1.50],
            'gemini-3.8-flash' => ['input' => 0.75, 'output' => 3.75],
        ],

        'web_search_cost_per_call' => 0.01,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fetching
    |--------------------------------------------------------------------------
    */

    'fetch' => [
        'user_agent' => env('CURATION_USER_AGENT', 'Mozilla/5.0 (compatible; CurationBot/1.0)'),
        'timeout' => 20,
        'max_items_per_fetch' => 50,
        'max_consecutive_errors' => 5,
        'per_domain_per_minute' => 20,
        'per_workspace_per_minute' => 60,
    ],

    'dedupe' => [
        'cluster_window_days' => 7,
        'title_similarity' => 0.82,
    ],

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    */

    'extractor' => [
        'url' => env('EXTRACTOR_URL', 'http://127.0.0.1:8100'),
        'token' => env('EXTRACTOR_TOKEN'),
        'timeout' => 90,
    ],

    'rsshub' => [
        'url' => env('RSSHUB_URL'),
    ],

    'youtube' => [
        'api_key' => env('YOUTUBE_API_KEY'),
    ],

    'instagram' => [
        // "official" (Graph API Business Discovery) or "apify".
        'driver' => env('INSTAGRAM_DRIVER', 'official'),
        'graph' => [
            'version' => env('INSTAGRAM_GRAPH_VERSION', 'v25.0'),
            'ig_user_id' => env('INSTAGRAM_IG_USER_ID'),
            'access_token' => env('INSTAGRAM_ACCESS_TOKEN'),
        ],
        'apify' => [
            'token' => env('APIFY_TOKEN'),
            'actor' => env('APIFY_INSTAGRAM_ACTOR', 'apify~instagram-scraper'),
        ],
    ],

    'api' => [
        'cache_seconds' => 300,
        'per_page' => 20,
        'max_per_page' => 100,
    ],

];
