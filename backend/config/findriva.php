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
