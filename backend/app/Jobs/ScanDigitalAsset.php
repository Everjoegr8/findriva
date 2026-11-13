<?php

namespace App\Jobs;

use App\Models\Business;
use App\Models\DigitalAsset;
use App\Models\Workspace;
use App\Services\Assets\AssetScanner;
use App\Services\Audit\AuditRunner;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanDigitalAsset implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $workspaceId, public readonly int $assetId)
    {
        $this->onQueue('scan');
    }

    public function handle(CurrentWorkspace $tenant, AssetScanner $scanner, AuditRunner $runner): void
    {
        $tenant->runAs(Workspace::findOrFail($this->workspaceId), function () use ($scanner, $runner) {
            $asset = DigitalAsset::query()->findOrFail($this->assetId);
            $scanner->scan($asset);
            $runner->refreshIdentity(Business::query()->findOrFail($asset->business_id));
        });
    }
}
