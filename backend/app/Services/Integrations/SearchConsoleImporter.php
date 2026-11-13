<?php

namespace App\Services\Integrations;

use App\Models\DomainProperty;
use App\Models\IntegrationConnection;
use App\Models\SearchPerformance;
use App\Services\AuditLogger;
use App\Services\Crawler\UrlNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Imports finished days of Search Analytics data and links the property to the business's domain. */
class SearchConsoleImporter
{
    public function __construct(private readonly GoogleSearchConsole $google, private readonly AuditLogger $audit) {}

    /** Picks the property that matches the business website, preferring the whole-domain property. */
    public function matchProperty(array $sites, ?string $website): ?string
    {
        $host = $website ? preg_replace('/^www\./', '', (string) UrlNormalizer::host($website)) : null;
        if (! $host) {
            return count($sites) === 1 ? $sites[0]['site_url'] : null;
        }
        $usable = array_filter($sites, fn ($s) => $s['permission'] !== 'siteUnverifiedUser');
        foreach (["sc-domain:{$host}", "https://{$host}/", "https://www.{$host}/", "http://{$host}/", "http://www.{$host}/"] as $candidate) {
            foreach ($usable as $s) {
                if (strtolower($s['site_url']) === strtolower($candidate)) {
                    return $s['site_url'];
                }
            }
        }

        return null;
    }

    /** Google confirming the account owns the matching property is proof of ownership. */
    public function markDomainVerified(IntegrationConnection $c): void
    {
        $permission = collect($c->available_properties ?? [])->firstWhere('site_url', $c->property)['permission'] ?? null;
        if (! in_array($permission, ['siteOwner', 'siteFullUser', 'siteRestrictedUser'], true)) {
            return;
        }
        $host = preg_replace('/^www\./', '', str_starts_with($c->property, 'sc-domain:') ? substr($c->property, 10) : (string) UrlNormalizer::host($c->property));
        $domain = DomainProperty::query()->where('business_id', $c->business_id)->get()
            ->first(fn ($d) => preg_replace('/^www\./', '', $d->domain) === $host);
        if ($domain && $domain->verification_status !== 'verified') {
            $domain->update(['verification_status' => 'verified', 'verified_at' => now(), 'verification_method' => 'google_search_console']);
            $this->audit->log('domain.verified', $domain, null, ['method' => 'google_search_console', 'property' => $c->property]);
        }
    }

    /** @return array{days: int, total_rows: int, detail_rows: int, through: ?string} */
    public function sync(IntegrationConnection $c): array
    {
        $cfg = config('findriva.search_console');
        $end = CarbonImmutable::today()->subDays($cfg['lag_days']);
        $start = $c->data_through
            ? CarbonImmutable::parse($c->data_through)->subDays(2) // re-read the last days in case Google revised them
            : $end->subDays($cfg['backfill_days'] - 1);
        if ($start->greaterThan($end)) {
            return ['days' => 0, 'total_rows' => 0, 'detail_rows' => 0, 'through' => $c->data_through?->toDateString()];
        }
        $range = ['startDate' => $start->toDateString(), 'endDate' => $end->toDateString(), 'dataState' => 'final', 'type' => 'web'];

        $totals = $this->google->query($c, $range + ['dimensions' => ['date'], 'rowLimit' => $cfg['max_rows']]);
        $detail = [];
        for ($page = 0; $page < $cfg['max_pages']; $page++) {
            $rows = $this->google->query($c, $range + ['dimensions' => ['date', 'query', 'page'], 'rowLimit' => $cfg['max_rows'], 'startRow' => $page * $cfg['max_rows']]);
            array_push($detail, ...$rows);
            if (count($rows) < $cfg['max_rows']) {
                break;
            }
        }

        DB::transaction(function () use ($c, $totals, $detail, $start, $end) {
            SearchPerformance::query()->where('integration_connection_id', $c->id)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])->delete();
            $now = now();
            $insert = [];
            foreach ($totals as $r) {
                $insert[] = $this->row($c, 'total', $r['keys'][0], null, null, $r, $now);
            }
            foreach ($detail as $r) {
                $insert[] = $this->row($c, 'query_page', $r['keys'][0], $r['keys'][1] ?? null, $r['keys'][2] ?? null, $r, $now);
            }
            foreach (array_chunk($insert, 500) as $chunk) {
                SearchPerformance::query()->insert($chunk);
            }
        });

        $through = collect($totals)->max(fn ($r) => $r['keys'][0]) ?? $c->data_through?->toDateString();
        $c->update(['last_synced_at' => now(), 'data_through' => $through, 'last_error' => null, 'status' => 'connected']);

        return ['days' => $start->diffInDays($end) + 1, 'total_rows' => count($totals), 'detail_rows' => count($detail), 'through' => $through];
    }

    private function row(IntegrationConnection $c, string $grain, string $date, ?string $query, ?string $page, array $r, $now): array
    {
        return [
            'workspace_id' => $c->workspace_id, 'business_id' => $c->business_id, 'integration_connection_id' => $c->id,
            'source' => 'google_search_console', 'date' => $date, 'grain' => $grain, 'query' => $query, 'page' => $page,
            'row_hash' => hash('sha256', $grain."\0".$date."\0".$query."\0".$page),
            'clicks' => (int) ($r['clicks'] ?? 0), 'impressions' => (int) ($r['impressions'] ?? 0),
            'ctr' => round((float) ($r['ctr'] ?? 0), 6), 'position' => round((float) ($r['position'] ?? 0), 2),
            'created_at' => $now, 'updated_at' => $now,
        ];
    }
}
