<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Google Business Profile APIs. Google only grants the `business.manage` scope, which can also
 * write; Findriva reads, and writes a review reply only after an owner or admin approves it.
 */
class GoogleBusinessProfile extends GoogleClient
{
    private const ACCOUNTS = 'https://mybusinessaccountmanagement.googleapis.com/v1';

    private const INFO = 'https://mybusinessbusinessinformation.googleapis.com/v1';

    private const V4 = 'https://mybusiness.googleapis.com/v4';

    private const PERFORMANCE = 'https://businessprofileperformance.googleapis.com/v1';

    public const METRICS = [
        'CALL_CLICKS', 'WEBSITE_CLICKS', 'BUSINESS_DIRECTION_REQUESTS', 'BUSINESS_CONVERSATIONS',
        'BUSINESS_IMPRESSIONS_MOBILE_SEARCH', 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
    ];

    protected string $product = 'Google Business Profile';

    public function scope(): string
    {
        return (string) config('findriva.business_profile.scope');
    }

    /**
     * Every location this Google account manages, across its accounts.
     *
     * @return list<array{name: string, account: string, title: string, address: ?string, phone: ?string, website: ?string}>
     */
    public function locations(IntegrationConnection $c): array
    {
        $out = [];
        foreach ($this->paged($c, self::ACCOUNTS.'/accounts', [], 'accounts', 5) as $account) {
            $locations = $this->paged($c, self::INFO.'/'.$account['name'].'/locations', [
                'readMask' => 'name,title,phoneNumbers,websiteUri,storefrontAddress',
                'pageSize' => 100,
            ], 'locations', 10);
            foreach ($locations as $l) {
                $out[] = [
                    // v4 review endpoints need the account-qualified name.
                    'name' => $account['name'].'/'.$l['name'],
                    'account' => (string) ($account['accountName'] ?? $account['name']),
                    'title' => (string) ($l['title'] ?? ''),
                    'address' => self::address($l['storefrontAddress'] ?? null),
                    'phone' => $l['phoneNumbers']['primaryPhone'] ?? null,
                    'website' => $l['websiteUri'] ?? null,
                ];
            }
        }

        return $out;
    }

    /** The facts Google shows on the profile, for comparison with the business's own facts. */
    public function profile(IntegrationConnection $c): array
    {
        $location = self::locationPart($c->property);
        $r = $this->authed($c, fn (string $t) => Http::withToken($t)->acceptJson()->timeout(20)->get(self::INFO.'/'.$location, [
            'readMask' => 'name,title,phoneNumbers,websiteUri,categories,storefrontAddress,regularHours,serviceArea,profile,metadata',
        ]));
        $l = $r->json();

        return [
            'title' => $l['title'] ?? null,
            'phone' => $l['phoneNumbers']['primaryPhone'] ?? null,
            'additional_phones' => $l['phoneNumbers']['additionalPhones'] ?? [],
            'website' => $l['websiteUri'] ?? null,
            'primary_category' => $l['categories']['primaryCategory']['displayName'] ?? null,
            'additional_categories' => collect($l['categories']['additionalCategories'] ?? [])->pluck('displayName')->filter()->values()->all(),
            'address' => self::address($l['storefrontAddress'] ?? null),
            'has_hours' => ! empty($l['regularHours']['periods']),
            'description' => $l['profile']['description'] ?? null,
            'maps_uri' => $l['metadata']['mapsUri'] ?? null,
            'review_uri' => $l['metadata']['newReviewUri'] ?? null,
        ];
    }

    /** @return list<array<string, mixed>> raw v4 reviews, newest first */
    public function reviews(IntegrationConnection $c, int $maxPages = 10): array
    {
        return $this->paged($c, self::V4.'/'.$c->property.'/reviews', ['pageSize' => 50, 'orderBy' => 'updateTime desc'], 'reviews', $maxPages);
    }

    public function reply(IntegrationConnection $c, string $reviewId, string $comment): array
    {
        $url = self::V4.'/'.$c->property.'/reviews/'.rawurlencode($reviewId).'/reply';

        return $this->authed($c, fn (string $t) => Http::withToken($t)->acceptJson()->timeout(20)->put($url, ['comment' => $comment]))->json() ?? [];
    }

    /**
     * Daily values per metric. Google leaves a day out when the value is zero.
     *
     * @return array<string, array<string, int>> metric => [Y-m-d => value]
     */
    public function dailyMetrics(IntegrationConnection $c, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $location = self::locationPart($c->property);
        $query = implode('&', array_merge(
            array_map(fn ($m) => 'dailyMetrics='.$m, self::METRICS),
            [
                'dailyRange.start_date.year='.$from->year, 'dailyRange.start_date.month='.$from->month, 'dailyRange.start_date.day='.$from->day,
                'dailyRange.end_date.year='.$to->year, 'dailyRange.end_date.month='.$to->month, 'dailyRange.end_date.day='.$to->day,
            ],
        ));
        $url = self::PERFORMANCE.'/'.$location.':fetchMultiDailyMetricsTimeSeries?'.$query;
        $r = $this->authed($c, fn (string $t) => Http::withToken($t)->acceptJson()->timeout(30)->get($url));

        $out = [];
        foreach ($r->json('multiDailyMetricTimeSeries') ?? [] as $group) {
            foreach ($group['dailyMetricTimeSeries'] ?? [] as $series) {
                $metric = (string) ($series['dailyMetric'] ?? '');
                foreach ($series['timeSeries']['datedValues'] ?? [] as $dv) {
                    $d = $dv['date'] ?? null;
                    if (! $metric || ! $d) {
                        continue;
                    }
                    $date = sprintf('%04d-%02d-%02d', $d['year'], $d['month'], $d['day']);
                    $out[$metric][$date] = (int) ($dv['value'] ?? 0);
                }
            }
        }

        return $out;
    }

    /** "accounts/1/locations/2" → "locations/2" (the Business Information and Performance APIs use the bare name). */
    public static function locationPart(?string $name): string
    {
        return preg_replace('#^accounts/[^/]+/#', '', (string) $name);
    }

    private function paged(IntegrationConnection $c, string $url, array $query, string $key, int $maxPages): array
    {
        $items = [];
        $token = null;
        for ($page = 0; $page < $maxPages; $page++) {
            $r = $this->authed($c, fn (string $t) => Http::withToken($t)->acceptJson()->timeout(30)->get($url, $query + ($token ? ['pageToken' => $token] : [])));
            array_push($items, ...($r->json($key) ?? []));
            $token = $r->json('nextPageToken');
            if (! $token) {
                break;
            }
        }

        return $items;
    }

    private static function address(?array $a): ?string
    {
        if (! $a) {
            return null;
        }
        $parts = array_merge($a['addressLines'] ?? [], [$a['sublocality'] ?? null, $a['locality'] ?? null, $a['administrativeArea'] ?? null]);

        return implode(', ', array_filter($parts)) ?: null;
    }

    protected function forbiddenMessage(): string
    {
        return 'Google says this account cannot manage that Business Profile, or Business Profile API access has not been approved for Findriva yet.';
    }
}
