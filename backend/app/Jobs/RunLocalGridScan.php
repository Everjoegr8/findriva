<?php

namespace App\Jobs;

use App\Models\LocalGridScan;
use App\Models\Workspace;
use App\Services\Rankings\LocalGrid;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunLocalGridScan implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $workspaceId, public int $scanId)
    {
        $this->onQueue('scan');
    }

    public function handle(CurrentWorkspace $tenant, LocalGrid $grid): void
    {
        $tenant->runAs(Workspace::findOrFail($this->workspaceId), function () use ($grid) {
            $scan = LocalGridScan::query()->find($this->scanId);
            if ($scan && $scan->status === 'queued') {
                $grid->run($scan);
            }
        });
    }

    public function failed(): void
    {
        LocalGridScan::withoutGlobalScopes()->where('id', $this->scanId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error' => 'The scan stopped unexpectedly.']);
    }
}
