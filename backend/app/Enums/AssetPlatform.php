<?php

namespace App\Enums;

enum AssetPlatform: string
{
    case Website = 'website';
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case TikTok = 'tiktok';
    case WhatsApp = 'whatsapp';
    case X = 'x';
    case LinkedIn = 'linkedin';
    case YouTube = 'youtube';
    case Shopify = 'shopify';
    case Jumia = 'jumia';
    case Konga = 'konga';
    case GoogleBusinessProfile = 'google_business_profile';
    case BingPlaces = 'bing_places';
    case AppleBusinessConnect = 'apple_business_connect';
    case Directory = 'directory';
    case Marketplace = 'marketplace';
    case Other = 'other';

    /** Host suffixes used to auto-detect a platform from a pasted URL. */
    private const HOSTS = [
        'instagram.com' => self::Instagram,
        'facebook.com' => self::Facebook,
        'fb.com' => self::Facebook,
        'tiktok.com' => self::TikTok,
        'wa.me' => self::WhatsApp,
        'whatsapp.com' => self::WhatsApp,
        'x.com' => self::X,
        'twitter.com' => self::X,
        'linkedin.com' => self::LinkedIn,
        'youtube.com' => self::YouTube,
        'youtu.be' => self::YouTube,
        'myshopify.com' => self::Shopify,
        'jumia.com.ng' => self::Jumia,
        'konga.com' => self::Konga,
        'business.google.com' => self::GoogleBusinessProfile,
        'g.page' => self::GoogleBusinessProfile,
        'maps.app.goo.gl' => self::GoogleBusinessProfile,
        'bingplaces.com' => self::BingPlaces,
    ];

    public static function detect(string $url): self
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^(www\.|m\.)/', '', $host) ?? $host;
        foreach (self::HOSTS as $suffix => $platform) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return $platform;
            }
        }

        return self::Website;
    }

    public function isSocial(): bool
    {
        return in_array($this, [self::Instagram, self::Facebook, self::TikTok, self::X, self::LinkedIn, self::YouTube, self::WhatsApp], true);
    }
}
