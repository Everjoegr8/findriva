<?php

namespace App\Jobs;

use App\Models\Business;
use App\Models\Workspace;
use App\Services\Rankings\RankTracker;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckRankings implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $workspaceId, public int $businessId, public bool $scheduled = true)
    {
        $this->onQueue('scan');
    }

    public function uniqueId(): string
    {
        return 'ranks-'.$this->businessId;
    }

    public function handle(CurrentWorkspace $tenant, RankTracker $tracker): void
    {
        $tenant->runAs(Workspace::findOrFail($this->workspaceId), function () use ($tracker) {
            if ($business = Business::query()->find($this->businessId)) {
                $tracker->run($business, $this->scheduled);
            }
        });
    }
}
