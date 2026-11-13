<?php

namespace App\Services\Facts;

use App\Enums\AssetPlatform;
use App\Models\Business;
use App\Models\BusinessLocation;

/**
 * The single, owner-approved source of truth that every generated output (fixes, the public page,
 * keyword seeds, content) is built from. Nothing generated may state a fact that is not in here.
 */
class BusinessFacts
{
    public const DAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

    private const COUNTRIES = ['NG' => 'Nigeria', 'GH' => 'Ghana', 'KE' => 'Kenya', 'ZA' => 'South Africa', 'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada'];

    /** Labels used when telling the owner what to add. */
    public const LABELS = [
        'category' => 'business category',
        'description' => 'a description of at least 50 characters',
        'location' => 'a city or service area',
        'phone' => 'a phone or WhatsApp number',
        'email' => 'an email address',
        'hours' => 'opening hours',
        'offerings' => 'at least one service or product',
        'website' => 'a website address',
        'profiles' => 'a social or directory profile',
        'image' => 'a logo or photo URL',
    ];

    /** @return array<string, mixed> */
    public function for(Business $business): array
    {
        $business->loadMissing(['aliases', 'locations', 'contacts', 'services', 'products', 'assets']);
        $locations = $business->locations->sortByDesc('is_primary')->values();

        $contacts = fn (string $type) => $business->contacts->where('type', $type)->sortByDesc('is_primary')
            ->map(fn ($c) => $c->normalized_value ?: $c->value)->filter()->unique()->values()->all();

        $locs = $locations->map(fn (BusinessLocation $l) => $this->location($l, $business->country))->all();
        $cities = collect($locs)->pluck('city')->filter()->unique()->values();
        $areas = collect($locs)->flatMap(fn ($l) => $l['service_areas'])->filter()->unique()->values();

        $profiles = $business->assets
            ->filter(fn ($a) => $a->platform !== AssetPlatform::Website && $a->ownership_status !== 'not_owned' && self::isWebUrl($a->url))
            ->map(fn ($a) => ['platform' => $a->platform->value, 'url' => $a->url, 'handle' => $a->handle])->values()->all();
        $image = $business->assets->map(fn ($a) => $a->scan_data['image'] ?? null)->filter(fn ($u) => self::isWebUrl($u))->first();

        return [
            'name' => $business->name,
            'legal_name' => $business->legal_name,
            'aliases' => $business->aliases->pluck('alias')->values()->all(),
            'description' => trim((string) $business->description) ?: null,
            'category' => $business->primary_category,
            'secondary_categories' => array_values($business->secondary_categories ?? []),
            'country' => $business->country,
            'country_name' => self::COUNTRIES[$business->country] ?? $business->country,
            'language' => $business->default_language,
            'website' => self::isWebUrl($business->website_url) ? $business->website_url : null,
            'phones' => $contacts('phone'),
            'whatsapp' => $contacts('whatsapp'),
            'emails' => $contacts('email'),
            'locations' => $locs,
            'primary_location' => $locs[0] ?? null,
            'cities' => $cities->all(),
            'areas_served' => $cities->merge($areas)->unique()->values()->all(),
            'is_online_only' => $locations->isNotEmpty() && $locations->every(fn ($l) => $l->location_type === 'online'),
            'services' => $business->services->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'description' => $s->description, 'url' => $s->url])->values()->all(),
            'products' => $business->products->where('status', 'active')->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'description' => $p->description,
                'price' => $p->price !== null ? (float) $p->price : null, 'currency' => $p->currency, 'url' => $p->url,
            ])->values()->all(),
            'profiles' => $profiles,
            'image' => $image,
        ];
    }

    /** @return list<string> keys of LABELS that are missing */
    public function missing(array $facts): array
    {
        $checks = [
            'category' => ! empty($facts['category']),
            'description' => mb_strlen((string) $facts['description']) >= 50,
            'location' => ! empty($facts['areas_served']),
            'phone' => ! empty($facts['phones']) || ! empty($facts['whatsapp']),
            'email' => ! empty($facts['emails']),
            'hours' => collect($facts['locations'])->contains(fn ($l) => ! empty($l['hours'])),
            'offerings' => ! empty($facts['services']) || ! empty($facts['products']),
            'website' => ! empty($facts['website']),
            'profiles' => ! empty($facts['profiles']),
            'image' => ! empty($facts['image']),
        ];

        return array_keys(array_filter($checks, fn ($ok) => ! $ok));
    }

    /** Only http(s) links ever reach generated markup or the public page. */
    public static function isWebUrl(?string $url): bool
    {
        return is_string($url) && preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $url) === 1;
    }

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? str_replace('_', ' ', $key);
    }

    /** @return array<string, mixed> */
    private function location(BusinessLocation $l, string $businessCountry): array
    {
        $public = $l->address_is_public && $l->location_type !== 'service_area';

        return [
            'label' => $l->label,
            // A home-based or service-area business may not want its street address published.
            'street' => $public ? trim(implode(', ', array_filter([$l->address_line1, $l->address_line2]))) ?: null : null,
            'city' => $l->city,
            'region' => $l->region,
            'postal_code' => $public ? $l->postal_code : null,
            'country' => $l->country ?: $businessCountry,
            'type' => $l->location_type,
            'address_is_public' => $public,
            'latitude' => $public ? $l->latitude : null,
            'longitude' => $public ? $l->longitude : null,
            'service_areas' => array_values(array_filter($l->service_areas ?? [])),
            'service_radius_km' => $l->service_radius_km,
            'hours' => self::hours($l->opening_hours ?? []),
        ];
    }

    /**
     * Normalises opening hours to [day => [[opens, closes], ...]]. Accepts "09:00-18:00" strings.
     *
     * @return array<string, list<array{0: string, 1: string}>>
     */
    public static function hours(array $raw): array
    {
        $out = [];
        foreach (self::DAYS as $day => $_) {
            foreach ((array) ($raw[$day] ?? []) as $range) {
                if (is_string($range) && preg_match('/^\s*(\d{1,2})[:.](\d{2})\s*[-–to]+\s*(\d{1,2})[:.](\d{2})\s*$/u', $range, $m)) {
                    $out[$day][] = [sprintf('%02d:%s', $m[1], $m[2]), sprintf('%02d:%s', $m[3], $m[4])];
                }
            }
        }

        return $out;
    }
}
