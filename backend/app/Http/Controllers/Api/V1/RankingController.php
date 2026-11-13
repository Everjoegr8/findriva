<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Jobs\CheckRankings;
use App\Models\Business;
use App\Models\Competitor;
use App\Models\RankObservation;
use App\Services\AuditLogger;
use App\Services\Rankings\OwnPresence;
use App\Services\Rankings\Platforms;
use App\Services\Rankings\RankTracker;
use App\Services\Rankings\SerpProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Live search positions for tracked keywords, and who shares the first page with the business. */
class RankingController extends Controller
{
    public function index(Business $business, RankTracker $tracker, SerpProvider $provider): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        $limits = $this->workspace()->entitlements();
        $allowance = (int) ($limits['rank_tracked_keywords'] ?? 0);

        $tracked = $tracker->trackedKeywords($business);
        $history = RankObservation::query()->whereIn('keyword_id', $tracked->pluck('id'))
            ->where('checked_at', '>=', now()->subDays(120))->orderBy('checked_at')->get()->groupBy('keyword_id');

        $rows = $tracked->values()->map(function ($k, $i) use ($history, $allowance) {
            $obs = $history->get($k->id, collect());
            $latest = $obs->last();
            $previous = $obs->count() > 1 ? $obs->get($obs->count() - 2) : null;

            return [
                'keyword_id' => $k->id,
                'keyword' => $k->keyword,
                'intent' => $k->intent,
                'search_volume' => $k->search_volume,
                'within_allowance' => $i < $allowance,
                'checked' => $latest !== null,
                'position' => $latest?->position,
                'url' => $latest?->url,
                'depth' => $latest?->depth,
                'checked_at' => $latest?->checked_at?->toIso8601String(),
                'previous_position' => $previous?->position,
                // Positive = moved up. Null when either check did not find the business.
                'change' => ($latest?->position && $previous?->position) ? $previous->position - $latest->position : null,
                'serp_features' => $latest?->serp_features ?? [],
                'top_results' => $latest?->top_results ?? [],
                'history' => $obs->map(fn ($o) => ['date' => $o->checked_at->toDateString(), 'position' => $o->position])->values(),
            ];
        });

        $checked = $rows->where('checked', true);

        return response()->json([
            'data' => $rows,
            'summary' => [
                'tracked' => $rows->count(),
                'checked' => $checked->count(),
                'top_3' => $checked->filter(fn ($r) => $r['position'] && $r['position'] <= 3)->count(),
                'top_10' => $checked->filter(fn ($r) => $r['position'] && $r['position'] <= 10)->count(),
                'not_found' => $checked->whereNull('position')->count(),
                'last_checked_at' => $checked->max('checked_at'),
            ],
            'method' => [
                'provider' => $provider->name(),
                'configured' => $provider->isConfigured(),
                'search_engine' => 'google',
                'market' => $business->country ?: 'NG',
                'device' => config('findriva.rankings.device', 'desktop'),
                'depth' => (int) config('findriva.rankings.depth', 30),
                'cadence_days' => (int) config('findriva.rankings.recheck_days', 7),
            ],
            'allowance' => $allowance,
            'cap_usd_per_day' => (float) ($limits['rank_spend_usd_per_day'] ?? 0),
            'last_run' => Cache::get(RankTracker::lastRunKey($business)),
        ]);
    }

    public function check(Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::AuditRun);
        $this->ensureInWorkspace($business);
        CheckRankings::dispatch($business->workspace_id, $business->id, false);

        return response()->json(['data' => ['status' => 'queued', 'last_run' => Cache::get(RankTracker::lastRunKey($business))]], 202);
    }

    public function competitors(Request $request, Business $business, RankTracker $tracker): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        $filters = $request->validate([
            'kind' => ['nullable', Rule::in(['business', 'platform'])],
            'status' => ['nullable', Rule::in(['suggested', 'tracked', 'dismissed', 'all'])],
        ]);

        $latest = $tracker->latestObservations($business)->load('keyword:id,keyword');
        $own = OwnPresence::for($business);

        $list = Competitor::query()->where('business_id', $business->id)
            ->when($filters['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when(($filters['status'] ?? null) && $filters['status'] !== 'all', fn ($q) => $q->where('status', $filters['status']),
                fn ($q) => ($filters['status'] ?? null) === 'all' ? $q : $q->where('status', '!=', 'dismissed'))
            ->orderByRaw("case status when 'tracked' then 0 when 'suggested' then 1 else 2 end")
            ->orderByDesc('shared_keywords')->orderBy('best_position')->orderBy('domain')
            ->limit(200)->get();

        return response()->json([
            'data' => $list->map(fn (Competitor $c) => [
                'id' => $c->id, 'domain' => $c->domain, 'name' => $c->name, 'kind' => $c->kind, 'source' => $c->source, 'status' => $c->status,
                'shared_keywords' => $c->shared_keywords, 'best_position' => $c->best_position, 'average_position' => $c->average_position,
                'last_seen_at' => $c->last_seen_at?->toIso8601String(),
                // Head to head on each keyword's latest check: their best result vs. the business's.
                'keywords' => $latest->map(function (RankObservation $o) use ($c) {
                    $theirs = collect($o->top_results ?? [])->first(fn ($r) => ($r['domain'] ?? '') === $c->domain);

                    return $theirs ? [
                        'keyword' => $o->keyword?->keyword, 'their_position' => (int) $theirs['position'], 'their_url' => $theirs['url'],
                        'your_position' => $o->position,
                    ] : null;
                })->filter()->sortBy('their_position')->values(),
            ]),
            'checked_keywords' => $latest->count(),
            'own_hosts' => $own->hosts,
        ]);
    }

    public function store(Request $request, Business $business, AuditLogger $audit): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $this->ensureInWorkspace($business);
        $data = $request->validate(['domain' => ['required', 'string', 'max:255'], 'name' => ['nullable', 'string', 'max:120']]);

        $raw = trim($data['domain']);
        $host = parse_url(preg_match('#^https?://#i', $raw) ? $raw : 'https://'.$raw, PHP_URL_HOST);
        $domain = preg_replace('/^www\./', '', strtolower((string) $host));
        if (! $domain || ! preg_match('/^(?=.{3,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw ValidationException::withMessages(['domain' => 'Enter a website address such as rival.com.ng.']);
        }
        if (OwnPresence::for($business)->ownsDomain($domain)) {
            throw ValidationException::withMessages(['domain' => 'That is your own website.']);
        }

        $c = Competitor::query()->firstOrNew(['business_id' => $business->id, 'domain' => $domain]);
        $c->fill([
            'name' => $data['name'] ?? $c->name,
            'kind' => $c->exists ? $c->kind : (Platforms::isPlatform($domain) ? 'platform' : 'business'),
            'source' => $c->exists ? $c->source : 'manual',
            'status' => 'tracked',
        ])->save();
        $audit->log('competitor.added', $business, null, ['domain' => $domain]);

        return response()->json(['data' => ['id' => $c->id, 'domain' => $c->domain, 'status' => $c->status]], $c->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, Business $business, Competitor $competitor): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $this->ensureBelongs($competitor, $business);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['suggested', 'tracked', 'dismissed'])],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'kind' => ['sometimes', Rule::in(['business', 'platform'])],
        ]);
        $competitor->update($data);

        return response()->json(['data' => ['id' => $competitor->id, 'status' => $competitor->status, 'kind' => $competitor->kind, 'name' => $competitor->name]]);
    }
}
