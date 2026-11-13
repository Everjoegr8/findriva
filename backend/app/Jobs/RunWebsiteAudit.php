<?php

namespace App\Jobs;

use App\Models\CrawlRun;
use App\Models\Workspace;
use App\Services\Audit\AuditRunner;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunWebsiteAudit implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public readonly int $workspaceId, public readonly int $crawlRunId)
    {
        $this->onQueue('crawl');
    }

    public function uniqueId(): string
    {
        return 'crawl-run-'.$this->crawlRunId;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(CurrentWorkspace $tenant, AuditRunner $runner): void
    {
        $workspace = Workspace::findOrFail($this->workspaceId);
        // Tenant scope is re-established inside the job; the run must belong to this workspace.
        $tenant->runAs($workspace, function () use ($runner) {
            $run = CrawlRun::query()->findOrFail($this->crawlRunId);
            $runner->runWebsite($run);
        });
    }
}
