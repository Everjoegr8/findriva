<?php

namespace App\Services\Identity;

use App\Enums\AssetPlatform;
use App\Models\Business;
use App\Services\Crawler\UrlNormalizer;
use App\Support\Phone;

/**
 * Checks the business's own identity record. Works with or without a website, because
 * structured business identity is mandatory even when a website is optional.
 */
class IdentityChecker
{
    /** Weighted components of the identity completeness score (sums to 100). */
    public const WEIGHTS = [
        'name' => 10,
        'category' => 10,
        'description' => 10,
        'location' => 15,
        'phone' => 15,
        'email' => 5,
        'hours' => 10,
        'offerings' => 10,
        'online_presence' => 10,
        'website' => 5,
    ];

    /** @return array{score: int, components: array<string, bool>, missing: list<string>} */
    public function completeness(Business $business): array
    {
        $business->loadMissing(['locations', 'contacts', 'services', 'products', 'assets']);

        $components = [
            'name' => trim((string) $business->name) !== '',
            'category' => ! empty($business->primary_category),
            'description' => mb_strlen(trim((string) $business->description)) >= 50,
            'location' => $business->locations->contains(fn ($l) => ! empty($l->city) || ! empty($l->service_areas)),
            'phone' => $business->contacts->whereIn('type', ['phone', 'whatsapp'])->isNotEmpty(),
            'email' => $business->contacts->where('type', 'email')->isNotEmpty(),
            'hours' => $business->locations->isNotEmpty() && $business->locations->every(fn ($l) => ! empty($l->opening_hours)),
            'offerings' => $business->services->isNotEmpty() || $business->products->isNotEmpty(),
            'online_presence' => $business->hasWebsite() || $business->assets->isNotEmpty(),
            'website' => $business->hasWebsite(),
        ];

        $score = 0;
        foreach ($components as $k => $ok) {
            $score += $ok ? self::WEIGHTS[$k] : 0;
        }

        return [
            'score' => $score,
            'components' => $components,
            'missing' => array_keys(array_filter($components, fn ($ok) => ! $ok)),
        ];
    }

    /** @return list<array{field: string, message: string, values: list<string>}> */
    public function conflicts(Business $business): array
    {
        $business->loadMissing(['locations', 'contacts', 'assets', 'aliases']);
        $conflicts = [];

        $primaryPhones = $business->contacts
            ->where('type', 'phone')->where('is_primary', true)
            ->pluck('normalized_value')->filter()->unique()->values();
        if ($primaryPhones->count() > 1) {
            $conflicts[] = ['field' => 'phone', 'message' => 'More than one number is marked as the primary phone.', 'values' => $primaryPhones->all()];
        }

        foreach ($business->contacts->whereIn('type', ['phone', 'whatsapp']) as $c) {
            if (! Phone::looksValid($c->normalized_value)) {
                $conflicts[] = ['field' => $c->type, 'message' => 'This number does not look like a valid phone number.', 'values' => [$c->value]];
            }
        }

        foreach ($business->locations as $l) {
            if ($l->country && strtoupper($l->country) !== strtoupper($business->country)) {
                $conflicts[] = ['field' => 'country', 'message' => 'A location is in a different country from the business.', 'values' => [$business->country, $l->country]];
            }
        }

        if ($business->hasWebsite()) {
            $siteHost = UrlNormalizer::host($business->website_url);
            $assetHosts = $business->assets->where('platform', AssetPlatform::Website)
                ->map(fn ($a) => UrlNormalizer::host($a->url))->filter(fn ($h) => $h !== $siteHost)->unique()->values();
            if ($assetHosts->isNotEmpty()) {
                $conflicts[] = ['field' => 'website', 'message' => 'More than one website is registered. Which one is the official site?', 'values' => array_merge([$siteHost], $assetHosts->all())];
            }

            $freeMail = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'ymail.com', 'live.com', 'aol.com', 'proton.me', 'protonmail.com'];
            foreach ($business->contacts->where('type', 'email') as $c) {
                $domain = strtolower(substr(strrchr($c->value, '@') ?: '', 1));
                if ($domain && ! in_array($domain, $freeMail, true) && $domain !== $siteHost && ! str_ends_with($siteHost, '.'.$domain) && ! str_ends_with($domain, '.'.$siteHost)) {
                    $conflicts[] = ['field' => 'email', 'message' => 'The email domain differs from the website domain.', 'values' => [$c->value, $siteHost]];
                }
            }
        }

        foreach ($business->assets as $a) {
            $scanned = $a->scan_data['site_name'] ?? null;
            if ($scanned && ! self::similarName($scanned, $business->name, $business->aliases->pluck('alias')->all())) {
                $conflicts[] = ['field' => 'name', 'message' => "The name on your {$a->platform->value} profile differs from your business name.", 'values' => [$business->name, $scanned]];
            }
        }

        return $conflicts;
    }

    /** @return list<array{code: string, url: ?string, evidence: array, crawled_page_id: null}> */
    public function findings(Business $business): array
    {
        $c = $this->completeness($business);
        $map = [
            'category' => 'identity_missing_category',
            'description' => 'identity_thin_description',
            'location' => 'identity_missing_location',
            'phone' => 'identity_missing_phone',
            'hours' => 'identity_missing_hours',
            'offerings' => 'identity_missing_offerings',
            'online_presence' => 'identity_no_presence',
        ];

        $out = [];
        foreach ($map as $component => $code) {
            if (! $c['components'][$component]) {
                $out[] = ['code' => $code, 'url' => null, 'evidence' => ['component' => $component], 'crawled_page_id' => null];
            }
        }
        if ($c['components']['online_presence'] && ! $c['components']['website']) {
            $out[] = ['code' => 'identity_no_website', 'url' => null, 'evidence' => ['assets' => $business->assets->count()], 'crawled_page_id' => null];
        }
        if ($business->locations->contains(fn ($l) => $l->location_type !== 'online')
            && ! $business->assets->contains(fn ($a) => $a->platform === AssetPlatform::GoogleBusinessProfile)) {
            $out[] = ['code' => 'google_profile_missing', 'url' => null, 'evidence' => ['locations' => $business->locations->count()], 'crawled_page_id' => null];
        }
        $conflicts = $this->conflicts($business);
        if ($conflicts) {
            $out[] = ['code' => 'identity_conflict', 'url' => null, 'evidence' => ['conflicts' => $conflicts], 'crawled_page_id' => null];
        }
        $unreachable = $business->assets->where('scan_status', 'unreachable')->values();
        if ($unreachable->isNotEmpty()) {
            $out[] = ['code' => 'asset_unreachable', 'url' => $unreachable->first()->url, 'evidence' => [
                'affected_count' => $unreachable->count(),
                'affected' => $unreachable->map(fn ($a) => ['url' => $a->url, 'platform' => $a->platform->value, 'error' => $a->scan_data['error'] ?? null])->all(),
            ], 'crawled_page_id' => null];
        }

        return $out;
    }

    private static function similarName(string $a, string $b, array $aliases = []): bool
    {
        $n = fn ($s) => preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s));
        foreach (array_merge([$b], $aliases) as $candidate) {
            if ($n($candidate) !== '' && (str_contains($n($a), $n($candidate)) || str_contains($n($candidate), $n($a)))) {
                return true;
            }
        }

        return false;
    }
}
