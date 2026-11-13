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
        ],
        'starter' => [
            'label' => 'Starter',
            'businesses' => 1,
            'locations_per_business' => 3,
            'pages_per_crawl' => 150,
            'crawls_per_month' => 30,
        ],
        'growth' => [
            'label' => 'Growth',
            'businesses' => 5,
            'locations_per_business' => 10,
            'pages_per_crawl' => 500,
            'crawls_per_month' => 120,
        ],
        'agency' => [
            'label' => 'Agency',
            'businesses' => 50,
            'locations_per_business' => 50,
            'pages_per_crawl' => 1000,
            'crawls_per_month' => 1000,
        ],
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
