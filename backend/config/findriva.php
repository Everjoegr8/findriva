<?php

/*
| Findriva product configuration. Plan limits are the coverage entitlements: what a workspace
| may use. They never buy ranking; they buy scope (more businesses, locations, pages checked).
*/

return [
    'plans' => [
        'free' => [
            'label' => 'Free snapshot',
            'businesses' => 1,
            'locations_per_business' => 1,
            'pages_per_crawl' => 25,
            'crawls_per_month' => 4,
            // Daily ceiling on paid keyword-data lookups (USD). 0 = seeds only, nothing measured.
            'keyword_spend_usd_per_day' => 0.00,
            'content_generations_per_day' => 5,
        ],
        'starter' => [
            'label' => 'Starter',
            'businesses' => 1,
            'locations_per_business' => 3,
            'pages_per_crawl' => 150,
            'crawls_per_month' => 30,
            // Daily ceiling on paid keyword-data lookups (USD). 0 = seeds only, nothing measured.
            'keyword_spend_usd_per_day' => 0.50,
            'content_generations_per_day' => 30,
        ],
        'growth' => [
            'label' => 'Growth',
            'businesses' => 5,
            'locations_per_business' => 10,
            'pages_per_crawl' => 500,
            'crawls_per_month' => 120,
            // Daily ceiling on paid keyword-data lookups (USD). 0 = seeds only, nothing measured.
            'keyword_spend_usd_per_day' => 2.00,
            'content_generations_per_day' => 100,
        ],
        'agency' => [
            'label' => 'Agency',
            'businesses' => 50,
            'locations_per_business' => 50,
            'pages_per_crawl' => 1000,
            'crawls_per_month' => 1000,
            // Daily ceiling on paid keyword-data lookups (USD). 0 = seeds only, nothing measured.
            'keyword_spend_usd_per_day' => 10.00,
            'content_generations_per_day' => 500,
        ],
    ],

    // Base URL of public business pages (https://findriva.com/b/{slug}).
    'public_url' => env('FINDRIVA_PUBLIC_URL', env('APP_URL', 'http://localhost')),

    'keywords' => [
        // dataforseo, or null for "seeds only". Volumes stay "not measured" without a provider.
        'provider' => env('FINDRIVA_KEYWORD_PROVIDER'),
        'cache_days' => 30,
        'max_seeds' => 60,
        'max_ideas' => 150,
        'dataforseo' => [
            'login' => env('DATAFORSEO_LOGIN'),
            'password' => env('DATAFORSEO_PASSWORD'),
            'base_url' => env('DATAFORSEO_BASE_URL', 'https://api.dataforseo.com'),
            // Used for the spend cap before the provider reports the real cost.
            'estimated_cost_per_request' => 0.075,
        ],
    ],

    'content' => [
        // template (facts only, no AI) or claude. Claude needs ANTHROPIC_API_KEY.
        'generator' => env('FINDRIVA_CONTENT_GENERATOR', 'template'),
        'claude_model' => env('FINDRIVA_CLAUDE_MODEL', 'claude-opus-5-5'),
    ],

    'crawler' => [
        'user_agent' => env('FINDRIVA_CRAWLER_UA', 'FindrivaBot/0.1 (+https://findriva.com/bot)'),
        'robots_token' => 'findrivabot',
        'max_depth' => (int) env('FINDRIVA_CRAWLER_MAX_DEPTH', 3),
        'max_redirects' => 5,
        'timeout_seconds' => (int) env('FINDRIVA_CRAWLER_TIMEOUT', 10),
        'connect_timeout_seconds' => 5,
        'max_body_bytes' => (int) env('FINDRIVA_CRAWLER_MAX_BYTES', 2 * 1024 * 1024),
        'max_runtime_seconds' => (int) env('FINDRIVA_CRAWLER_MAX_RUNTIME', 300),
        'default_delay_ms' => (int) env('FINDRIVA_CRAWLER_DELAY_MS', 250),
        'max_crawl_delay_seconds' => 5,
        'allowed_ports' => [80, 443],
        // Development only: lets the crawler reach a local test site. Never enable in production.
        'allow_private_networks' => (bool) env('FINDRIVA_CRAWLER_ALLOW_PRIVATE', false),
    ],
];
