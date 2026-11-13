<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessRules;
use App\Http\Requests\OnboardBusinessRequest;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use App\Models\IntegrationConnection;
use App\Models\SearchPerformance;
use App\Services\Audit\AuditRunner;
use App\Services\AuditLogger;
use App\Services\BusinessService;
use App\Services\Crawler\UrlNormalizer;
use App\Services\Identity\IdentityChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BusinessController extends Controller
{
    public function __construct(
        private readonly BusinessService $service,
        private readonly AuditRunner $runner,
        private readonly IdentityChecker $identity,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission(Permission::View);
        $q = Business::query()
            ->withCount(['locations', 'assets', 'issues as open_issues_count' => fn ($q) => $q->where('status', 'open')])
            ->with('latestCrawlRun')
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where('name', 'like', '%'.$s.'%'))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('name');

        return BusinessResource::collection($q->paginate(min(100, (int) $request->integer('per_page', 25))));
    }

    public function store(OnboardBusinessRequest $request): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessCreate);
        $business = $this->service->onboard($this->workspace(), $request->user(), $request->validated());
        $this->runner->refreshIdentity($business);

        return $this->show($business->fresh())->setStatusCode(201);
    }

    public function show(Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        $business->load(['aliases', 'locations', 'contacts', 'services', 'products', 'assets', 'domains', 'latestCrawlRun']);

        return response()->json([
            'data' => new BusinessResource($business),
            'identity' => [
                'completeness' => $this->identity->completeness($business),
                'conflicts' => $this->identity->conflicts($business),
            ],
        ]);
    }

    public function update(Request $request, Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $this->ensureInWorkspace($business);
        $data = $request->validate(BusinessRules::business(partial: true) + ['status' => ['sometimes', 'in:active,paused']]);
        if (array_key_exists('website_url', $data)) {
            $data['website_url'] = $data['website_url'] ? UrlNormalizer::normalize($data['website_url']) : null;
        }
        if (isset($data['country'])) {
            $data['country'] = strtoupper($data['country']);
        }
        $before = $business->only(array_keys($data));
        $business->update($data);
        $this->service->syncDomain($business);
        $this->audit->log('business.updated', $business, $before, $data);
        $this->runner->refreshIdentity($business);

        return $this->show($business->fresh());
    }

    public function destroy(Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessDelete);
        $this->ensureInWorkspace($business);
        $business->delete();
        $this->audit->log('business.deleted', $business, ['name' => $business->name], null);

        return response()->json(null, 204);
    }

    /**
     * The overview keeps each measure separate. Identity completeness, website health, fixes
     * done and verified, and search-engine data are different things and are never blended
     * into one score.
     */
    public function overview(Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        $business->load(['latestCrawlRun', 'assets']);

        $issues = $business->issues()->where('status', 'open')->get();
        $actions = $business->actions()->get();
        $run = $business->latestCrawlRun;

        return response()->json(['data' => [
            'identity_completeness' => $this->identity->completeness($business),
            'website' => $business->hasWebsite() ? [
                'url' => $business->website_url,
                'last_audit' => $run?->only('id', 'status', 'pages_checked', 'issues_found', 'finished_at'),
                'open_issues_by_severity' => $issues->whereNotNull('crawl_run_id')->groupBy(fn ($i) => $i->severity->value)->map->count(),
            ] : null,
            'profiles' => [
                'registered' => $business->assets->count(),
                'by_platform' => $business->assets->groupBy(fn ($a) => $a->platform->value)->map->count(),
                'reachable' => $business->assets->where('scan_status', 'ok')->count(),
            ],
            'actions' => [
                'open' => $actions->whereIn('status.value', ['open', 'awaiting_approval', 'approved', 'in_progress', 'blocked'])->count(),
                'awaiting_approval' => $actions->where('status.value', 'awaiting_approval')->count(),
                'done_unverified' => $actions->where('status.value', 'done')->count(),
                'verified' => $actions->where('status.value', 'verified')->count(),
            ],
            // Only from the business's own connected accounts. Never estimated.
            'search_engine_data' => $this->searchData($business),
            'open_issues_by_category' => $issues->groupBy('category')->map->count(),
        ]]);
    }

    private function searchData(Business $business): array
    {
        $c = IntegrationConnection::query()->where('business_id', $business->id)->where('provider', 'google_search_console')->first();
        $gsc = ['status' => 'not_connected', 'note' => 'Impressions, clicks and indexing are reported only from your own Google Search Console once connected.'];
        if ($c) {
            $gsc = ['status' => $c->status, 'property' => $c->property, 'data_through' => $c->data_through?->toDateString()];
            if ($c->data_through) {
                $from = $c->data_through->copy()->subDays(27)->toDateString();
                $rows = SearchPerformance::query()->where('integration_connection_id', $c->id)->where('grain', 'total')
                    ->whereBetween('date', [$from, $c->data_through->toDateString()])->get();
                $gsc['last_28_days'] = ['clicks' => (int) $rows->sum('clicks'), 'impressions' => (int) $rows->sum('impressions')];
            }
        }
        $domain = $business->domains()->first();

        return [
            'search_console' => $gsc,
            'indexnow' => ['status' => $domain?->indexnow_verified_at ? 'key_verified' : 'not_set_up'],
            'rank_tracking' => ['status' => 'not_started', 'note' => 'Rank observations need a licensed data provider; we never invent positions.'],
        ];
    }
}
