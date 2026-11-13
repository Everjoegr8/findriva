<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessRules;
use App\Jobs\ScanDigitalAsset;
use App\Models\Business;
use App\Models\DigitalAsset;
use App\Services\Audit\AuditRunner;
use App\Services\AuditLogger;
use App\Services\BusinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The digital asset registry: every website, profile, storefront and listing a business has. */
class DigitalAssetController extends Controller
{
    public function __construct(
        private readonly BusinessService $service,
        private readonly AuditLogger $audit,
        private readonly AuditRunner $runner,
    ) {}

    public function index(Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);

        return response()->json(['data' => $business->assets()->orderBy('platform')->get()]);
    }

    public function store(Request $request, Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $this->ensureInWorkspace($business);
        $asset = $this->service->addAsset($business, $request->validate(BusinessRules::asset()));
        $this->audit->log('asset.created', $asset, null, $asset->only('platform', 'url'));
        $this->runner->refreshIdentity($business);

        return response()->json(['data' => $asset], 201);
    }

    public function update(Request $request, Business $business, DigitalAsset $asset): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $this->ensureBelongs($asset, $business);
        $data = $request->validate([
            'handle' => ['sometimes', 'nullable', 'string', 'max:120'],
            'ownership_status' => ['sometimes', Rule::in(['claimed', 'unverified', 'not_owned'])],
            // "manual" = the customer is following a guided setup; "connected" is set only by a real OAuth flow.
            'connection_state' => ['sometimes', Rule::in(['not_connected', 'manual'])],
        ]);
        $before = $asset->only(array_keys($data));
        $asset->update($data);
        $this->audit->log('asset.updated', $asset, $before, $data);

        return response()->json(['data' => $asset]);
    }

    public function rescan(Business $business, DigitalAsset $asset): JsonResponse
    {
        $this->authorizePermission(Permission::AuditRun);
        $this->ensureBelongs($asset, $business);
        $asset->update(['scan_status' => 'pending']);
        ScanDigitalAsset::dispatch($business->workspace_id, $asset->id);

        return response()->json(['data' => $asset->fresh()], 202);
    }

    public function destroy(Business $business, DigitalAsset $asset): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $this->ensureBelongs($asset, $business);
        $asset->delete();
        $this->audit->log('asset.deleted', $asset, $asset->only('platform', 'url'), null);
        $this->runner->refreshIdentity($business);

        return response()->json(null, 204);
    }
}
