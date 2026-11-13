<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Keyword;
use App\Services\Fixes\FixGenerator;
use App\Services\Keywords\KeywordResearch;
use App\Services\Keywords\KeywordResearchProvider;
use App\Services\Publishing\PublicPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Ready-made fixes, the public page and keyword research for one business. */
class GrowthController extends Controller
{
    public function fixes(Business $business, FixGenerator $fixes): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);

        return response()->json(['data' => $fixes->for($business), 'has_website' => $business->hasWebsite()]);
    }

    public function publication(Business $business, PublicPage $page): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);

        return response()->json(['data' => $this->publicationState($business, $page)]);
    }

    public function publish(Request $request, Business $business, PublicPage $page): JsonResponse
    {
        // Putting a page in front of search engines is an owner/admin decision.
        $this->authorizePermission(Permission::ActionApprove);
        $this->ensureInWorkspace($business);
        $data = $request->validate(['slug' => ['nullable', 'string', 'min:3', 'max:120', 'regex:/^[a-z0-9-]+$/']]);
        $page->publish($business, $request->user(), $data['slug'] ?? null);

        return response()->json(['data' => $this->publicationState($business->fresh(), $page)]);
    }

    public function unpublish(Business $business, PublicPage $page): JsonResponse
    {
        $this->authorizePermission(Permission::ActionApprove);
        $this->ensureInWorkspace($business);
        $page->unpublish($business);

        return response()->json(['data' => $this->publicationState($business->fresh(), $page)]);
    }

    public function keywords(Request $request, Business $business, KeywordResearchProvider $provider): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        $filters = $request->validate([
            'intent' => ['nullable', Rule::in(['transactional', 'local', 'commercial', 'informational', 'navigational'])],
            'status' => ['nullable', Rule::in(['suggested', 'tracked', 'dismissed'])],
            'cluster_id' => ['nullable', 'integer'],
        ]);

        $keywords = Keyword::query()->where('business_id', $business->id)
            ->when($filters['intent'] ?? null, fn ($q, $v) => $q->where('intent', $v))
            ->when($filters['cluster_id'] ?? null, fn ($q, $v) => $q->where('keyword_cluster_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v), fn ($q) => $q->where('status', '!=', 'dismissed'))
            ->orderByRaw('search_volume is null')->orderByDesc('search_volume')->orderBy('keyword')
            ->limit(500)->get();

        return response()->json([
            'data' => $keywords->map(fn (Keyword $k) => [
                'id' => $k->id, 'keyword' => $k->keyword, 'intent' => $k->intent, 'status' => $k->status,
                'cluster_id' => $k->keyword_cluster_id, 'origin' => $k->origin, 'origin_detail' => $k->origin_detail,
                'market' => $k->market,
                'measured' => $k->metrics_retrieved_at !== null,
                'search_volume' => $k->search_volume, 'competition' => $k->competition, 'cpc' => $k->cpc, 'cpc_currency' => $k->cpc_currency,
                'metrics_provider' => $k->metrics_provider, 'metrics_retrieved_at' => $k->metrics_retrieved_at?->toIso8601String(),
            ]),
            'clusters' => $business->keywordClusters()->withCount('keywords')->orderBy('name')->get(['id', 'name', 'intent', 'theme']),
            'provider' => ['name' => $provider->name(), 'configured' => $provider->isConfigured()],
            'budget' => ['cap_usd_per_day' => (float) ($this->workspace()->entitlements()['keyword_spend_usd_per_day'] ?? 0)],
        ]);
    }

    public function research(Business $business, KeywordResearch $research): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $this->ensureInWorkspace($business);

        return response()->json(['data' => $research->run($business)]);
    }

    public function updateKeyword(Request $request, Business $business, Keyword $keyword): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $this->ensureBelongs($keyword, $business);
        $data = $request->validate(['status' => ['required', Rule::in(['suggested', 'tracked', 'dismissed'])]]);
        $keyword->update($data);

        return response()->json(['data' => ['id' => $keyword->id, 'status' => $keyword->status]]);
    }

    private function publicationState(Business $business, PublicPage $page): array
    {
        return [
            'is_published' => $business->is_published,
            'slug' => $business->public_slug,
            'suggested_slug' => $business->public_slug ?: $page->suggestSlug($business),
            'url' => $business->is_published ? PublicPage::url($business) : null,
            'published_at' => $business->published_at?->toIso8601String(),
            'gate' => $page->gate($business),
            'can_publish' => (bool) request()->user()?->canIn($this->workspace(), Permission::ActionApprove),
        ];
    }
}
