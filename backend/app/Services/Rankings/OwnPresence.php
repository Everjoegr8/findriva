<?php

namespace App\Services\Rankings;

use App\Models\Business;
use App\Services\Crawler\UrlNormalizer;
use App\Services\Publishing\PublicPage;

/**
 * Which search results belong to the business: its own website (any subdomain), and its exact
 * profile URLs on platforms (an Instagram profile, its Findriva public page). A different
 * Instagram account on instagram.com is not the business.
 */
class OwnPresence
{
    /** @param  list<string>  $hosts @param  list<string>  $prefixes */
    private function __construct(public readonly array $hosts, public readonly array $prefixes) {}

    public static function for(Business $business): self
    {
        $hosts = [];
        if ($business->website_url) {
            $hosts[] = UrlNormalizer::host($business->website_url);
        }
        foreach ($business->domains()->get(['domain', 'canonical_host']) as $d) {
            foreach ([$d->domain, $d->canonical_host] as $h) {
                if ($h) {
                    $hosts[] = preg_replace('/^www\./', '', strtolower($h));
                }
            }
        }

        $prefixes = [];
        foreach ($business->assets()->pluck('url') as $url) {
            if ($p = self::key((string) $url)) {
                $prefixes[] = $p;
            }
        }
        if ($business->is_published && ($url = PublicPage::url($business))) {
            $prefixes[] = self::key($url);
        }

        return new self(array_values(array_unique(array_filter($hosts))), array_values(array_unique(array_filter($prefixes))));
    }

    public function owns(string $url): bool
    {
        $host = UrlNormalizer::host($url);
        foreach ($this->hosts as $own) {
            if ($host === $own || str_ends_with($host, '.'.$own)) {
                return true;
            }
        }
        $key = self::key($url);
        foreach ($this->prefixes as $p) {
            if ($key === $p || str_starts_with($key, $p.'/') || str_starts_with($key, $p.'?')) {
                return true;
            }
        }

        return false;
    }

    public function ownsDomain(string $domain): bool
    {
        $domain = preg_replace('/^www\./', '', strtolower($domain));
        foreach ($this->hosts as $own) {
            if ($domain === $own || str_ends_with($domain, '.'.$own)) {
                return true;
            }
        }

        return false;
    }

    /** host + path, lowercased, without scheme, www, fragment or trailing slash. */
    private static function key(string $url): ?string
    {
        $p = parse_url(trim($url));
        if (empty($p['host'])) {
            return null;
        }
        $host = preg_replace('/^(www|m)\./', '', strtolower($p['host']));

        return rtrim($host.strtolower($p['path'] ?? ''), '/');
    }
}
