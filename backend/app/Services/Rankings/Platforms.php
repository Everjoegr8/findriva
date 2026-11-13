<?php

namespace App\Services\Rankings;

/**
 * Sites that host many businesses (social networks, directories, marketplaces, encyclopaedias).
 * They show up in local results a lot but are places to be listed, not competitors.
 */
final class Platforms
{
    public const DOMAINS = [
        'instagram.com', 'facebook.com', 'tiktok.com', 'x.com', 'twitter.com', 'youtube.com', 'linkedin.com', 'pinterest.com',
        'threads.net', 'whatsapp.com', 'wa.me', 'snapchat.com', 'reddit.com', 'quora.com', 'medium.com',
        'wikipedia.org', 'google.com', 'yelp.com', 'tripadvisor.com', 'foursquare.com', 'yellowpages.com', 'bing.com', 'apple.com',
        'jiji.ng', 'jumia.com.ng', 'konga.com', 'nairaland.com', 'vconnect.com', 'businesslist.com.ng', 'finelib.com',
        'ngcareers.com', 'nigeriagalleria.com', 'connectnigeria.com', 'yellowpages.ng', 'propertypro.ng', 'nigeriapropertycentre.com',
        'booking.com', 'airbnb.com', 'hotels.ng', 'chowdeck.com', 'glovoapp.com', 'fiverr.com', 'upwork.com', 'etsy.com', 'amazon.com',
    ];

    public static function isPlatform(string $domain): bool
    {
        $domain = preg_replace('/^www\./', '', strtolower($domain));
        foreach (self::DOMAINS as $p) {
            if ($domain === $p || str_ends_with($domain, '.'.$p)) {
                return true;
            }
        }

        return false;
    }
}
