<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Jobs\RunLocalGridScan;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Keyword;
use App\Models\LocalGridScan;
use App\Services\Rankings\LocalGrid;
use App\Services\Rankings\MapsProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LocalGridController extends Controller
{
    public function index(Business $business, LocalGrid $grid, MapsProvider $maps): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        $allowance = (int) ($this->workspace()->entitlements()['local_grid_points_per_month'] ?? 0);

        return response()->json([
            'data' => LocalGridScan::query()->where('business_id', $business->id)->latest('id')->limit(30)->get()->map(fn ($s) => $this->present($s)),
            'locations' => BusinessLocation::query()->where('business_id', $business->id)->whereNotNull('latitude')->whereNotNull('longitude')
                ->get(['id', 'label', 'city', 'latitude', 'longitude', 'is_primary']),
            'keywords' => Keyword::query()->where('business_id', $business->id)->where('status', 'tracked')->orderBy('keyword')->get(['id', 'keyword']),
            'provider' => ['name' => $maps->name(), 'configured' => $maps->isConfigured()],
            'allowance' => ['points_per_month' => $allowance, 'used_this_month' => $grid->pointsUsedThisMonth($business)],
        ]);
    }

    public function show(Business $business, LocalGridScan $scan): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureBelongs($scan, $business);

        return response()->json(['data' => $this->present($scan, true)]);
    }

    public function store(Request $request, Business $business, LocalGrid $grid, MapsProvider $maps): JsonResponse
    {
        $this->authorizePermission(Permission::AuditRun);
        $this->ensureInWorkspace($business);
        $data = $request->validate([
            'keyword_id' => ['nullable', 'integer'],
            'keyword' => ['required_without:keyword_id', 'nullable', 'string', 'min:2', 'max:120'],
            'location_id' => ['nullable', 'integer'],
            'lat' => ['required_without:location_id', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['required_without:location_id', 'nullable', 'numeric', 'between:-180,180'],
            'size' => ['nullable', Rule::in([3, 5])],
            'spacing_km' => ['nullable', 'numeric', 'between:0.3,5'],
        ]);

        $keyword = isset($data['keyword_id']) ? Keyword::query()->where('business_id', $business->id)->find($data['keyword_id']) : null;
        if (isset($data['keyword_id']) && ! $keyword) {
            throw ValidationException::withMessages(['keyword_id' => 'Choose one of this business\'s keywords.']);
        }
        $location = isset($data['location_id']) ? BusinessLocation::query()->where('business_id', $business->id)->find($data['location_id']) : null;
        if (isset($data['location_id']) && (! $location || $location->latitude === null || $location->longitude === null)) {
            throw ValidationException::withMessages(['location_id' => 'That location has no map position. Add its latitude and longitude first.']);
        }
        if (! $maps->isConfigured()) {
            throw ValidationException::withMessages(['grid' => 'Map checks are not available yet because no maps results provider is connected.']);
        }
        $size = (int) ($data['size'] ?? 3);
        $points = $size * $size;
        $allowance = (int) ($this->workspace()->entitlements()['local_grid_points_per_month'] ?? 0);
        if ($allowance <= 0) {
            throw ValidationException::withMessages(['grid' => 'Your plan does not include map grid checks.']);
        }
        if ($grid->pointsUsedThisMonth($business) + $points > $allowance) {
            throw ValidationException::withMessages(['grid' => "This scan needs {$points} map points and your plan has ".max(0, $allowance - $grid->pointsUsedThisMonth($business)).' left this month.']);
        }
        if (LocalGridScan::query()->where('business_id', $business->id)->whereIn('status', ['queued', 'running'])->exists()) {
            throw ValidationException::withMessages(['grid' => 'A map scan is already running for this business.']);
        }

        $scan = LocalGridScan::create([
            'business_id' => $business->id, 'keyword_id' => $keyword?->id, 'business_location_id' => $location?->id,
            'requested_by' => $request->user()->id, 'keyword' => $keyword?->keyword ?? trim($data['keyword']),
            'center_lat' => (float) ($location?->latitude ?? $data['lat']), 'center_lng' => (float) ($location?->longitude ?? $data['lng']),
            'size' => $size, 'spacing_km' => (float) ($data['spacing_km'] ?? 1), 'depth' => 20,
            'provider' => $maps->name(), 'status' => 'queued',
        ]);
        RunLocalGridScan::dispatch($scan->workspace_id, $scan->id);

        return response()->json(['data' => $this->present($scan->fresh())], 202);
    }

    private function present(LocalGridScan $s, bool $full = false): array
    {
        $points = $s->points ?? [];
        $out = [
            'id' => $s->id, 'keyword' => $s->keyword, 'status' => $s->status, 'size' => $s->size, 'spacing_km' => $s->spacing_km,
            'center' => ['lat' => $s->center_lat, 'lng' => $s->center_lng], 'depth' => $s->depth,
            'points_checked' => count($points), 'points_found' => $s->points_found, 'average_position' => $s->average_position,
            'top_3_points' => collect($points)->filter(fn ($p) => $p['position'] !== null && $p['position'] <= 3)->count(),
            'error' => $s->error, 'created_at' => $s->created_at?->toIso8601String(), 'completed_at' => $s->completed_at?->toIso8601String(),
        ];
        if ($full) {
            $out['points'] = $points;
            // Who wins the most points in the top 3.
            $out['leaders'] = collect($points)->flatMap(fn ($p) => $p['top'] ?? [])->where('you', false)
                ->groupBy(fn ($x) => mb_strtolower($x['title']))
                ->map(fn ($g) => ['title' => $g->first()['title'], 'domain' => $g->first()['domain'], 'rating' => $g->first()['rating'], 'votes' => $g->first()['votes'], 'points_in_top_3' => $g->count()])
                ->sortByDesc('points_in_top_3')->values()->take(8);
        }

        return $out;
    }
}
