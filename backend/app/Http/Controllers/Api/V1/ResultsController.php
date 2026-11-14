<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\ActionTask;
use App\Models\AuditIssue;
use App\Models\Business;
use App\Models\ContentAsset;
use App\Models\IndexNowSubmission;
use App\Models\IntegrationConnection;
use App\Models\Keyword;
use App\Models\RankObservation;
use App\Models\SearchPerformance;
use App\Services\Identity\IdentityChecker;
use App\Services\Publishing\PublicPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Results and reports, built only from measured data. Each figure says where it came from,
 * and missing measurements are shown as missing, never estimated.
 */
class ResultsController extends Controller
{
    public function performance(Request $request, Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'in:7,28,90']])['days'] ?? 28);
        $c = $this->connection($business);
        if (! $c || ! $c->data_through) {
            return response()->json(['data' => ['connected' => (bool) $c, 'status' => $c?->status ?? 'not_connected', 'measured' => false]]);
        }

        $end = CarbonImmutable::parse($c->data_through);
        $start = $end->subDays($days - 1);
        $prevEnd = $start->subDay();
        $prevStart = $prevEnd->subDays($days - 1);
        $base = fn () => SearchPerformance::query()->where('business_id', $business->id)->where('integration_connection_id', $c->id);

        $series = $base()->where('grain', 'total')->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')->get(['date', 'clicks', 'impressions', 'ctr', 'position'])
            ->map(fn ($r) => ['date' => $r->date->toDateString(), 'clicks' => $r->clicks, 'impressions' => $r->impressions, 'ctr' => $r->ctr, 'position' => $r->position]);

        $detail = $base()->where('grain', 'query_page')->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get(['query', 'page', 'clicks', 'impressions', 'position']);
        $top = fn (string $field) => $detail->groupBy($field)->map(fn ($g, $k) => [
            $field => $k, 'clicks' => $g->sum('clicks'), 'impressions' => $g->sum('impressions'),
            // Impression-weighted average position, the same way Search Console reports it.
            'position' => round($g->sum(fn ($r) => $r->position * $r->impressions) / max(1, $g->sum('impressions')), 1),
        ])->sortByDesc('clicks')->sortByDesc(fn ($r) => [$r['clicks'], $r['impressions']])->take(25)->values();
        $queries = $top('query');

        $tracked = Keyword::query()->where('business_id', $business->id)->where('status', 'tracked')->get(['id', 'keyword', 'normalized']);
        $byQuery = $detail->groupBy(fn ($r) => Keyword::normalize((string) $r->query))->map(fn ($g) => [
            'clicks' => $g->sum('clicks'), 'impressions' => $g->sum('impressions'),
            'position' => round($g->sum(fn ($r) => $r->position * $r->impressions) / max(1, $g->sum('impressions')), 1),
        ]);

        return response()->json(['data' => [
            'connected' => true, 'measured' => true, 'source' => 'Google Search Console', 'property' => $c->property,
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'days' => $days],
            'last_synced_at' => $c->last_synced_at?->toIso8601String(),
            'totals' => $this->totals($base()->where('grain', 'total')->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()),
            'previous' => $this->totals($base()->where('grain', 'total')->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])->get()),
            'series' => $series,
            'top_queries' => $queries,
            'top_pages' => $top('page'),
            'tracked_keywords' => $tracked->map(fn ($k) => ['id' => $k->id, 'keyword' => $k->keyword] + (isset($byQuery[$k->normalized])
                ? ['seen' => true, 'clicks' => $byQuery[$k->normalized]['clicks'], 'impressions' => $byQuery[$k->normalized]['impressions'], 'position' => $byQuery[$k->normalized]['position']]
                : ['seen' => false]))->values(),
            'note' => 'Totals include searches Google keeps private, so query rows do not add up to the totals. Positions are averages, not a fixed rank.',
        ]]);
    }

    /** Bing Webmaster Tools figures: daily totals (no position) and weekly query rows. */
    public function bing(Request $request, Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'in:7,28,90']])['days'] ?? 28);
        $c = IntegrationConnection::query()->where('business_id', $business->id)->where('provider', 'bing_webmaster')->first();
        if (! $c || ! $c->data_through) {
            return response()->json(['data' => ['connected' => (bool) $c, 'status' => $c?->status ?? 'not_connected', 'measured' => false]]);
        }
        $end = CarbonImmutable::parse($c->data_through);
        $start = $end->subDays($days - 1);
        $prevEnd = $start->subDay();
        $prevStart = $prevEnd->subDays($days - 1);
        $base = fn (string $grain) => SearchPerformance::query()->where('integration_connection_id', $c->id)->where('grain', $grain);
        $sum = fn ($rows) => ['clicks' => (int) $rows->sum('clicks'), 'impressions' => (int) $rows->sum('impressions'), 'days' => $rows->count()];
        $current = $base('total')->whereBetween('date', [$start->toDateString(), $end->toDateString()])->orderBy('date')->get(['date', 'clicks', 'impressions']);
        // Query rows are weekly, so a period takes in every week that starts inside it.
        $weeks = $base('query_week')->whereBetween('date', [$start->subDays(6)->toDateString(), $end->toDateString()])->get(['query', 'clicks', 'impressions', 'position']);
        $queries = $weeks->groupBy('query')->map(fn ($g, $q) => [
            'query' => $q, 'clicks' => (int) $g->sum('clicks'), 'impressions' => (int) $g->sum('impressions'),
            'position' => round($g->sum(fn ($r) => $r->position * $r->impressions) / max(1, $g->sum('impressions')), 1),
        ])->sortByDesc(fn ($r) => [$r['clicks'], $r['impressions']])->take(25)->values();

        return response()->json(['data' => [
            'connected' => true, 'measured' => true, 'source' => 'Bing Webmaster Tools', 'property' => $c->property,
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'days' => $days],
            'last_synced_at' => $c->last_synced_at?->toIso8601String(),
            'totals' => $sum($current),
            'previous' => $sum($base('total')->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])->get()),
            'series' => $current->map(fn ($r) => ['date' => $r->date->toDateString(), 'clicks' => $r->clicks, 'impressions' => $r->impressions])->values(),
            'top_queries' => $queries,
            'note' => 'Bing also powers Yahoo and DuckDuckGo results. Bing reports queries by week, so query figures can cover a few days either side of the period.',
        ]]);
    }

    public function report(Request $request, Business $business, IdentityChecker $identity): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $end = $start->endOfMonth();
        $in = fn ($q, string $col = 'created_at') => $q->whereBetween($col, [$start, $end]);

        $c = $this->connection($business);
        $search = null;
        if ($c && $c->data_through) {
            $rows = SearchPerformance::query()->where('integration_connection_id', $c->id)->where('grain', 'total')
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get();
            $prev = SearchPerformance::query()->where('integration_connection_id', $c->id)->where('grain', 'total')
                ->whereBetween('date', [$start->subMonthNoOverflow()->toDateString(), $start->subDay()->toDateString()])->get();
            $search = ['source' => 'Google Search Console', 'property' => $c->property, 'days_measured' => $rows->count(),
                'totals' => $this->totals($rows), 'previous_month' => $this->totals($prev),
                // A month still in progress is not compared with a full month; that would look like a drop.
                'comparable' => $prev->count() > 0 && $rows->count() >= $prev->count()];
        }

        $bingC = IntegrationConnection::query()->where('business_id', $business->id)->where('provider', 'bing_webmaster')->first();
        $bing = null;
        if ($bingC && $bingC->data_through) {
            $rows = SearchPerformance::query()->where('integration_connection_id', $bingC->id)->where('grain', 'total')
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get();
            $bing = ['source' => 'Bing Webmaster Tools', 'property' => $bingC->property, 'days_measured' => $rows->count(),
                'clicks' => (int) $rows->sum('clicks'), 'impressions' => (int) $rows->sum('impressions')];
        }
        $ranks = RankObservation::query()->where('business_id', $business->id)->whereBetween('checked_at', [$start, $end]);

        return response()->json(['data' => [
            'business' => ['id' => $business->id, 'name' => $business->name],
            'month' => $month,
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'generated_at' => now()->toIso8601String(),
            'identity' => $identity->completeness($business),
            'issues' => [
                'found' => $in(AuditIssue::query()->where('business_id', $business->id))->count(),
                'resolved' => $in(AuditIssue::query()->where('business_id', $business->id)->where('status', 'resolved'), 'updated_at')->count(),
                'open_now' => AuditIssue::query()->where('business_id', $business->id)->where('status', 'open')->count(),
            ],
            'actions' => [
                'completed' => $in(ActionTask::query()->where('business_id', $business->id)->whereNotNull('completed_at'), 'completed_at')->count(),
                'verified' => $in(ActionTask::query()->where('business_id', $business->id)->whereNotNull('verified_at'), 'verified_at')->count(),
                'verified_titles' => $in(ActionTask::query()->where('business_id', $business->id)->whereNotNull('verified_at'), 'verified_at')->limit(20)->pluck('title'),
            ],
            'content_approved' => $in(ContentAsset::query()->where('business_id', $business->id)->where('status', 'approved'), 'reviewed_at')->count(),
            'public_page' => ['published' => $business->is_published, 'url' => $business->is_published ? PublicPage::url($business) : null],
            'indexnow' => [
                'submissions' => $in(IndexNowSubmission::query()->where('business_id', $business->id))->count(),
                'urls_accepted' => (int) $in(IndexNowSubmission::query()->where('business_id', $business->id)->where('outcome', 'accepted'))->sum('url_count'),
            ],
            'search' => $search,
            'bing' => $bing,
            'rank_checks' => ['checks' => (clone $ranks)->count(), 'keywords' => (clone $ranks)->distinct('keyword_id')->count('keyword_id')],
            'not_measured' => array_values(array_filter([
                $search ? null : 'Google clicks and impressions (connect Google Search Console)',
                $bing ? null : 'Bing clicks and impressions (connect Bing Webmaster Tools)',
                (clone $ranks)->exists() ? null : 'Live search positions (no rank checks ran this month)',
            ])),
        ]]);
    }

    private function connection(Business $business): ?IntegrationConnection
    {
        return IntegrationConnection::query()->where('business_id', $business->id)->where('provider', 'google_search_console')->first();
    }

    private function totals($rows): array
    {
        $impressions = $rows->sum('impressions');

        return [
            'clicks' => (int) $rows->sum('clicks'),
            'impressions' => (int) $impressions,
            'ctr' => $impressions > 0 ? round($rows->sum('clicks') / $impressions, 4) : null,
            'position' => $impressions > 0 ? round($rows->sum(fn ($r) => $r->position * $r->impressions) / $impressions, 1) : null,
            'days' => $rows->count(),
        ];
    }
}
