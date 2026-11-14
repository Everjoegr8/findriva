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
            // Tracked keywords whose live search position we check weekly, and the daily spend ceiling for it (USD).
            'rank_tracked_keywords' => 0,
            'rank_spend_usd_per_day' => 0.00,
            // Questions asked weekly to an AI assistant with web search, and the daily spend ceiling for it (USD).
            'ai_answer_questions' => 0,
            'ai_answer_spend_usd_per_day' => 0.00,
            // Google Maps points checked per month in local grid scans (a 3x3 scan uses 9, a 5x5 uses 25).
            'local_grid_points_per_month' => 0,
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
            // Tracked keywords whose live search position we check weekly, and the daily spend ceiling for it (USD).
            'rank_tracked_keywords' => 20,
            'rank_spend_usd_per_day' => 0.25,
            // Questions asked weekly to an AI assistant with web search, and the daily spend ceiling for it (USD).
            'ai_answer_questions' => 5,
            'ai_answer_spend_usd_per_day' => 0.50,
            // Google Maps points checked per month in local grid scans (a 3x3 scan uses 9, a 5x5 uses 25).
            'local_grid_points_per_month' => 100,
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
            // Tracked keywords whose live search position we check weekly, and the daily spend ceiling for it (USD).
            'rank_tracked_keywords' => 100,
            'rank_spend_usd_per_day' => 1.00,
            // Questions asked weekly to an AI assistant with web search, and the daily spend ceiling for it (USD).
            'ai_answer_questions' => 20,
            'ai_answer_spend_usd_per_day' => 2.00,
            // Google Maps points checked per month in local grid scans (a 3x3 scan uses 9, a 5x5 uses 25).
            'local_grid_points_per_month' => 500,
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
            // Tracked keywords whose live search position we check weekly, and the daily spend ceiling for it (USD).
            'rank_tracked_keywords' => 500,
            'rank_spend_usd_per_day' => 5.00,
            // Questions asked weekly to an AI assistant with web search, and the daily spend ceiling for it (USD).
            'ai_answer_questions' => 100,
            'ai_answer_spend_usd_per_day' => 10.00,
            // Google Maps points checked per month in local grid scans (a 3x3 scan uses 9, a 5x5 uses 25).
            'local_grid_points_per_month' => 2500,
        ],
    ],

    // Base URL of public business pages (https://findriva.com/b/{slug}).
    'public_url' => env('FINDRIVA_PUBLIC_URL', env('APP_URL', 'http://localhost')),

    // Monthly prices in kobo (NGN minor units). Plans buy coverage, never rankings.
    'billing' => [
        'currency' => 'NGN',
        'prices' => [
            'starter' => (int) env('FINDRIVA_PRICE_STARTER', 990000),   // ₦9,900
            'growth' => (int) env('FINDRIVA_PRICE_GROWTH', 2990000),    // ₦29,900
            'agency' => (int) env('FINDRIVA_PRICE_AGENCY', 9900000),    // ₦99,000
        ],
        // Paystack plan codes (PLN_...) turn a payment into a monthly subscription. Without them a payment buys 30 days.
        'paystack_plans' => [
            'starter' => env('PAYSTACK_PLAN_STARTER'),
            'growth' => env('PAYSTACK_PLAN_GROWTH'),
            'agency' => env('PAYSTACK_PLAN_AGENCY'),
        ],
        'grace_days' => 3,
    ],

    // Where the dashboard lives, for redirects after connecting an account.
    'app_url' => env('FINDRIVA_APP_URL', env('APP_URL', 'http://localhost')),

    'search_console' => [
        'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
        'backfill_days' => 90,
        // Google finalises data about 2-3 days late; we only import finished days.
        'lag_days' => 3,
        'max_rows' => 25000,
        'max_pages' => 4,
    ],

    'bing_webmaster' => [
        'scope' => 'webmaster.read',
        // Bing keeps about six months of daily traffic and weekly query data.
        'max_query_rows' => 5000,
    ],

    'rankings' => [
        // dataforseo, or empty for "not measured". Defaults to the keyword provider so one account covers both.
        'provider' => env('FINDRIVA_SERP_PROVIDER', env('FINDRIVA_KEYWORD_PROVIDER')),
        'device' => 'desktop',
        'depth' => (int) env('FINDRIVA_SERP_DEPTH', 30),
        'recheck_days' => 7,
        'manual_min_hours' => 20,
        'estimated_cost_per_check' => (float) env('FINDRIVA_SERP_COST_ESTIMATE', 0.004),
        'estimated_cost_per_map_point' => (float) env('FINDRIVA_MAPS_COST_ESTIMATE', 0.004),
    ],

    'ai_answers' => [
        // claude (needs ANTHROPIC_API_KEY), or empty to switch AI answer checks off.
        'engine' => env('FINDRIVA_AI_ANSWER_ENGINE', 'claude'),
        'model' => env('FINDRIVA_AI_ANSWER_MODEL', 'claude-opus-5-5'),
        'max_searches' => 3,
        // List prices used to record what each answer cost (USD).
        'input_usd_per_mtok' => (float) env('FINDRIVA_AI_ANSWER_INPUT_PRICE', 4.0),
        'output_usd_per_mtok' => (float) env('FINDRIVA_AI_ANSWER_OUTPUT_PRICE', 20.0),
        'search_usd_each' => 0.01,
        'estimated_cost' => (float) env('FINDRIVA_AI_ANSWER_COST_ESTIMATE', 0.08),
    ],

    'business_profile' => [
        'scope' => 'https://www.googleapis.com/auth/business.manage',
        'backfill_days' => 90,
        // Performance data settles a few days late; re-read the most recent days on each sync.
        'lag_days' => 3,
        'reread_days' => 7,
        'review_pages' => 10,
        'reply_max_length' => 4000,
    ],

    'indexnow' => [
        'endpoint' => env('INDEXNOW_ENDPOINT', 'https://api.indexnow.org/indexnow'),
        // Findriva's own key, for public business pages on our domain. Empty = do not submit.
        'platform_key' => env('FINDRIVA_INDEXNOW_KEY'),
        'max_urls' => 1000,
    ],

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
