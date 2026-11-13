<?php

namespace App\Services\Integrations;

use App\Models\Business;
use App\Models\IntegrationConnection;
use App\Models\ProfileMetric;
use App\Models\ProfileReview;
use App\Services\Crawler\UrlNormalizer;
use App\Services\Facts\BusinessFacts;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Imports a connected Google Business Profile: what the profile shows, its reviews, and daily actions. */
class BusinessProfileImporter
{
    private const STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    public function __construct(private readonly GoogleBusinessProfile $google, private readonly BusinessFacts $facts) {}

    /** @param  list<array<string, mixed>>  $locations */
    public function matchLocation(array $locations, Business $business): ?string
    {
        if (count($locations) === 1) {
            return $locations[0]['name'];
        }
        $phones = array_map(fn ($p) => Phone::normalize($p, $business->country ?: 'NG'), $this->facts->for($business)['phones']);
        $host = $business->website_url ? UrlNormalizer::host($business->website_url) : null;
        foreach ($locations as $l) {
            if (($host && $l['website'] && UrlNormalizer::host($l['website']) === $host)
                || ($l['phone'] && in_array(Phone::normalize($l['phone'], $business->country ?: 'NG'), $phones, true))) {
                return $l['name'];
            }
        }
        $name = mb_strtolower(trim($business->name));
        $byName = array_values(array_filter($locations, fn ($l) => mb_strtolower(trim($l['title'])) === $name));

        return count($byName) === 1 ? $byName[0]['name'] : null;
    }

    public function sync(IntegrationConnection $c): void
    {
        $business = $c->business;
        $c->update(['profile_snapshot' => $this->google->profile($c)]);
        $this->importReviews($c, $business);
        $this->importMetrics($c, $business);
        $c->update(['last_synced_at' => now(), 'status' => 'connected', 'last_error' => null]);
    }

    private function importReviews(IntegrationConnection $c, Business $business): void
    {
        $rows = $this->google->reviews($c, (int) config('findriva.business_profile.review_pages', 10));
        DB::transaction(function () use ($rows, $c, $business) {
            foreach ($rows as $r) {
                $id = (string) ($r['reviewId'] ?? basename((string) ($r['name'] ?? '')));
                if ($id === '') {
                    continue;
                }
                $review = ProfileReview::query()->firstOrNew(['integration_connection_id' => $c->id, 'external_id' => $id]);
                $liveReply = $r['reviewReply']['comment'] ?? null;
                $review->fill([
                    'business_id' => $business->id,
                    'reviewer_name' => ($r['reviewer']['isAnonymous'] ?? false) ? null : ($r['reviewer']['displayName'] ?? null),
                    'rating' => self::STARS[$r['starRating'] ?? ''] ?? null,
                    'comment' => $r['comment'] ?? null,
                    'reviewed_at' => isset($r['createTime']) ? CarbonImmutable::parse($r['createTime']) : null,
                    'source_updated_at' => isset($r['updateTime']) ? CarbonImmutable::parse($r['updateTime']) : null,
                    'reply_comment' => $liveReply,
                    'reply_updated_at' => isset($r['reviewReply']['updateTime']) ? CarbonImmutable::parse($r['reviewReply']['updateTime']) : null,
                ]);
                // A reply written elsewhere (for example in the Google app) is the live truth; keep a pending draft only if none exists.
                if ($liveReply && in_array($review->reply_status ?? 'none', ['none', 'failed'], true)) {
                    $review->reply_status = 'posted';
                }
                $review->save();
            }
        });
    }

    private function importMetrics(IntegrationConnection $c, Business $business): void
    {
        $cfg = config('findriva.business_profile');
        $to = CarbonImmutable::today()->subDays((int) $cfg['lag_days']);
        $latest = ProfileMetric::query()->where('integration_connection_id', $c->id)->max('date');
        $from = $latest
            ? CarbonImmutable::parse($latest)->subDays((int) $cfg['reread_days'])
            : $to->subDays((int) $cfg['backfill_days'] - 1);
        if ($from->greaterThan($to)) {
            return;
        }

        $series = $this->google->dailyMetrics($c, $from, $to);
        DB::transaction(function () use ($series, $c, $business, $from, $to) {
            // Google omits days whose value is zero, so every day in the range is stored, zero when absent.
            for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
                foreach (GoogleBusinessProfile::METRICS as $metric) {
                    ProfileMetric::query()->updateOrCreate(
                        ['integration_connection_id' => $c->id, 'date' => $d->toDateString(), 'metric' => $metric],
                        ['business_id' => $business->id, 'value' => $series[$metric][$d->toDateString()] ?? 0],
                    );
                }
            }
        });
        $c->update(['data_through' => $to->toDateString()]);
    }

    /**
     * Where the profile Google shows disagrees with the business's own facts in Findriva.
     *
     * @return list<array{field: string, label: string, findriva: mixed, google: mixed}>
     */
    public function differences(Business $business, ?array $snapshot): array
    {
        if (! $snapshot) {
            return [];
        }
        $facts = $this->facts->for($business);
        $country = $business->country ?: 'NG';
        $out = [];
        $add = function (string $field, string $label, $ours, $theirs) use (&$out) {
            $out[] = compact('field', 'label') + ['findriva' => $ours, 'google' => $theirs];
        };

        if ($snapshot['title'] && mb_strtolower(trim($snapshot['title'])) !== mb_strtolower(trim($facts['name']))
            && ! in_array(mb_strtolower(trim($snapshot['title'])), array_map(fn ($a) => mb_strtolower(trim($a)), $facts['aliases']), true)) {
            $add('name', 'Business name', $facts['name'], $snapshot['title']);
        }
        $ourPhones = array_filter(array_map(fn ($p) => Phone::normalize($p, $country), $facts['phones']));
        $gPhone = Phone::normalize($snapshot['phone'] ?? null, $country);
        if ($gPhone && $ourPhones && ! in_array($gPhone, $ourPhones, true)) {
            $add('phone', 'Phone', $facts['phones'][0], $snapshot['phone']);
        } elseif (! $gPhone && $ourPhones) {
            $add('phone', 'Phone', $facts['phones'][0], null);
        }
        $ourHost = $facts['website'] ? UrlNormalizer::host($facts['website']) : null;
        $gHost = ! empty($snapshot['website']) ? UrlNormalizer::host($snapshot['website']) : null;
        if ($ourHost !== $gHost && ($ourHost || $gHost)) {
            $add('website', 'Website', $facts['website'], $snapshot['website'] ?? null);
        }
        if (! $snapshot['has_hours']) {
            $add('hours', 'Opening hours', null, null);
        }
        if (! $snapshot['description']) {
            $add('description', 'Description', $facts['description'], null);
        }

        return $out;
    }
}
