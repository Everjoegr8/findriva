<?php

namespace App\Services\Content;

/** The kinds of copy the Content Studio drafts, with their length limits and where they go. */
class ContentTypes
{
    public const TYPES = [
        'business_description' => ['label' => 'About your business', 'max_chars' => 1500, 'applies_to' => 'business.description',
            'use' => 'Your Findriva profile, public page and website About section.'],
        'google_profile_description' => ['label' => 'Google Business Profile description', 'max_chars' => 750, 'applies_to' => null,
            'use' => 'Paste into Google Business Profile > Edit profile > Description. Google does not allow links or phone numbers here.'],
        'short_bio' => ['label' => 'Social media bio', 'max_chars' => 150, 'applies_to' => null,
            'use' => 'Instagram, TikTok, X or WhatsApp Business "About".'],
        'service_page' => ['label' => 'Service page', 'max_chars' => 4000, 'applies_to' => null, 'needs' => 'service_id',
            'use' => 'A page on your website (or a long post) about one service.'],
        'faq' => ['label' => 'Frequently asked questions', 'max_chars' => 4000, 'applies_to' => null,
            'use' => 'An FAQ section on your website or highlights on Instagram.'],
        'social_post' => ['label' => 'Introduction post', 'max_chars' => 1200, 'applies_to' => null,
            'use' => 'A post introducing the business on Instagram, Facebook, X or WhatsApp Status.'],
    ];

    public static function has(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function get(string $type): array
    {
        return self::TYPES[$type] + ['type' => $type];
    }
}
