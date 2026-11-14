<?php

namespace App\Jobs;

use App\Models\IntegrationConnection;
use App\Models\Workspace;
use App\Services\Integrations\BingWebmasterImporter;
use App\Services\Integrations\IntegrationException;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncBingWebmaster implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $workspaceId, public int $connectionId)
    {
        $this->onQueue('scan');
    }

    public function uniqueId(): string
    {
        return 'bing-'.$this->connectionId;
    }

    public function backoff(): array
    {
        return [60, 600];
    }

    public function handle(CurrentWorkspace $tenant, BingWebmasterImporter $importer): void
    {
        $tenant->runAs(Workspace::findOrFail($this->workspaceId), function () use ($importer) {
            $c = IntegrationConnection::query()->find($this->connectionId);
            if (! $c || $c->status === 'revoked' || ! $c->property) {
                return;
            }
            try {
                $importer->sync($c);
            } catch (IntegrationException $e) {
                $c->update(['status' => $e->needsReconnect ? 'revoked' : 'error', 'last_error' => $e->getMessage()]);
                if (! $e->needsReconnect && $this->attempts() < $this->tries) {
                    throw $e;
                }
            }
        });
    }
}
