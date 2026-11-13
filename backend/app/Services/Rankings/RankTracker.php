<?php

namespace App\Services\Rankings;

use App\Models\Business;
use App\Models\Competitor;
use App\Models\Keyword;
use App\Models\RankObservation;
use App\Models\UsageEvent;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Checks where the business appears in live search results for its tracked keywords, within the
 * plan's keyword allowance and daily spend cap, and keeps a competitor map from those results.
 */
class RankTracker
{
    public function __construct(private readonly SerpProvider $provider, private readonly AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public function run(Business $business, bool $scheduled = false): array
    {
        $limits = $business->workspace->entitlements();
        $allowance = (int) ($limits['rank_tracked_keywords'] ?? 0);
        $cap = (float) ($limits['rank_spend_usd_per_day'] ?? 0);
        $market = $business->country ?: 'NG';
        $language = $business->default_language ?: 'en';
        $device = (string) config('findriva.rankings.device', 'desktop');
        $depth = (int) config('findriva.rankings.depth', 30);
        // Weekly by schedule; a manual check refreshes anything older than about a day.
        $minAgeHours = $scheduled ? (int) config('findriva.rankings.recheck_days', 7) * 24 - 6 : (int) config('findriva.rankings.manual_min_hours', 20);

        $tracked = $this->trackedKeywords($business);
        $summary = [
            'provider' => $this->provider->name(),
            'tracked' => $tracked->count(),
            'allowance' => $allowance,
            'over_allowance' => max(0, $tracked->count() - $allowance),
            'checked' => 0,
            'skipped_recent' => 0,
            'cap_usd' => $cap,
            'spend_today_usd' => $this->spentToday($business),
        ];

        $status = match (true) {
            $tracked->isEmpty() => 'nothing_to_check',
            ! $this->provider->isConfigured() => 'not_configured',
            $allowance <= 0 || $cap <= 0 => 'plan_excludes',
            ! $this->provider->supportsMarket($market) => 'unsupported_market',
            default => null,
        };

        if ($status === null) {
            $status = 'checked';
            foreach ($tracked->take($allowance) as $keyword) {
                $last = RankObservation::query()->where('keyword_id', $keyword->id)->max('checked_at');
                if ($last && now()->diffInHours($last, true) < $minAgeHours) {
                    $summary['skipped_recent']++;

                    continue;
                }
                if ($cap + 1e-9 < $summary['spend_today_usd'] + $this->provider->estimatedCost()) {
                    $status = 'budget_exhausted';
                    break;
                }
                try {
                    $result = $this->provider->organic($keyword->keyword, $market, $language, $device, $depth);
                } catch (SerpException $e) {
                    Log::warning('serp provider failed', ['business_id' => $business->id, 'error' => $e->getMessage()]);
                    $status = 'provider_error';
                    break;
                }
                $this->record($business, $keyword, $result, $market, $language, $device, $depth);
                $summary['checked']++;
                $summary['spend_today_usd'] += $result['cost'];
            }
            if ($status === 'checked' && $summary['checked'] === 0 && $summary['skipped_recent'] > 0) {
                $status = 'up_to_date';
            }
        }

        if ($summary['checked'] > 0) {
            $this->rebuildCompetitors($business);
        }

        $summary['status'] = $status;
        $summary['spend_today_usd'] = round($this->spentToday($business), 4);
        $summary['message'] = $this->message($status, $summary);
        $summary['finished_at'] = now()->toIso8601String();
        Cache::put(self::lastRunKey($business), $summary, now()->addDays(14));
        if (! $scheduled || $summary['checked'] > 0) {
            $this->audit->log('rankings.checked', $business, null, $summary);
        }

        return $summary;
    }

    public static function lastRunKey(Business $business): string
    {
        return 'rankings:last:'.$business->id;
    }

    /** Tracked keywords, most searched first, so the allowance covers the ones that matter most. */
    public function trackedKeywords(Business $business): Collection
    {
        return Keyword::query()->where('business_id', $business->id)->where('status', 'tracked')
            ->orderByRaw('search_volume is null')->orderByDesc('search_volume')->orderBy('id')->get();
    }

    private function record(Business $business, Keyword $keyword, array $result, string $market, string $language, string $device, int $depth): void
    {
        $own = OwnPresence::for($business);
        $mine = collect($result['organic'])->first(fn ($r) => $own->owns($r['url']));

        DB::transaction(function () use ($business, $keyword, $result, $market, $language, $device, $depth, $mine) {
            RankObservation::create([
                'business_id' => $business->id, 'keyword_id' => $keyword->id, 'provider' => $this->provider->name(),
                'market' => $market, 'language' => $language, 'device' => $device, 'depth' => $depth,
                'position' => $mine['position'] ?? null, 'absolute_position' => $mine['absolute_position'] ?? null, 'url' => $mine['url'] ?? null,
                'serp_features' => $result['features'],
                'top_results' => array_slice($result['organic'], 0, 10),
                'cost' => $result['cost'], 'checked_at' => now(),
            ]);
            UsageEvent::create([
                'workspace_id' => $business->workspace_id, 'provider' => $this->provider->name(), 'action' => 'rank_check',
                'units' => 1, 'estimated_cost' => $result['cost'], 'context' => ['business_id' => $business->id, 'keyword_id' => $keyword->id],
            ]);
        });
    }

    /**
     * Who shares the first page with the business across its tracked keywords, from each keyword's
     * latest check. Owner choices (tracked, dismissed) and manually added competitors are kept.
     */
    public function rebuildCompetitors(Business $business): void
    {
        $own = OwnPresence::for($business);
        $stats = [];
        foreach ($this->latestObservations($business) as $obs) {
            $seen = [];
            foreach ($obs->top_results ?? [] as $r) {
                $domain = $r['domain'] ?? '';
                if ($domain === '' || isset($seen[$domain]) || $own->owns($r['url'] ?? '') || $own->ownsDomain($domain)) {
                    continue;
                }
                $seen[$domain] = true;
                $stats[$domain]['positions'][] = (int) $r['position'];
            }
        }

        DB::transaction(function () use ($business, $stats) {
            $existing = Competitor::query()->where('business_id', $business->id)->get()->keyBy('domain');
            foreach ($stats as $domain => $s) {
                $values = [
                    'shared_keywords' => count($s['positions']),
                    'best_position' => min($s['positions']),
                    'average_position' => round(array_sum($s['positions']) / count($s['positions']), 2),
                    'last_seen_at' => now(),
                ];
                if ($c = $existing->get($domain)) {
                    $c->update($values);
                } else {
                    Competitor::create($values + [
                        'business_id' => $business->id, 'domain' => $domain, 'name' => null,
                        'kind' => Platforms::isPlatform($domain) ? 'platform' : 'business', 'source' => 'search_results', 'status' => 'suggested',
                    ]);
                }
            }
            // No longer on page one for any tracked keyword.
            foreach ($existing as $domain => $c) {
                if (! isset($stats[$domain])) {
                    $c->update(['shared_keywords' => 0, 'best_position' => null, 'average_position' => null]);
                }
            }
        });
    }

    /** @return Collection<int, RankObservation> latest check per tracked keyword */
    public function latestObservations(Business $business): Collection
    {
        $ids = $this->trackedKeywords($business)->pluck('id');
        if ($ids->isEmpty()) {
            return new \Illuminate\Database\Eloquent\Collection;
        }
        $latest = RankObservation::query()->whereIn('keyword_id', $ids)
            ->selectRaw('max(id) as id')->groupBy('keyword_id')->pluck('id');

        return RankObservation::query()->whereIn('id', $latest)->get();
    }

    private function spentToday(Business $business): float
    {
        return (float) UsageEvent::query()->withoutGlobalScopes()->where('workspace_id', $business->workspace_id)
            ->where('action', 'rank_check')->where('created_at', '>=', now()->startOfDay())->sum('estimated_cost');
    }

    private function message(string $status, array $s): string
    {
        $extra = $s['over_allowance'] > 0 ? " {$s['over_allowance']} tracked keywords are beyond your plan's allowance of {$s['allowance']} and were not checked." : '';

        return match ($status) {
            'checked' => "Checked {$s['checked']} keywords in live search results.".$extra,
            'up_to_date' => 'Every tracked keyword was checked recently. Positions refresh weekly; you can check again tomorrow.',
            'nothing_to_check' => 'Track some keywords first (Keywords tab), then check where you appear.',
            'not_configured' => 'Positions are not measured yet because no search results provider is connected.',
            'plan_excludes' => 'Your plan does not include position checks. Search Console positions (if connected) still appear on Results.',
            'unsupported_market' => 'Position checks are not available for this country yet.',
            'budget_exhausted' => "Today's position-check budget for this workspace is used up. Checked {$s['checked']} keywords; the rest will be checked next time.",
            default => "The search results provider had a problem. Checked {$s['checked']} keywords; nothing else was charged.",
        };
    }
}
