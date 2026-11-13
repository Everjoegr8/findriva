<?php

namespace App\Jobs;

use App\Models\Business;
use App\Models\Workspace;
use App\Services\AiVisibility\AiVisibility;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckAiAnswers implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(public int $workspaceId, public int $businessId, public bool $scheduled = true)
    {
        $this->onQueue('scan');
    }

    public function uniqueId(): string
    {
        return 'ai-answers-'.$this->businessId;
    }

    public function handle(CurrentWorkspace $tenant, AiVisibility $ai): void
    {
        $tenant->runAs(Workspace::findOrFail($this->workspaceId), function () use ($ai) {
            if ($business = Business::query()->find($this->businessId)) {
                $ai->run($business, $this->scheduled);
            }
        });
    }
}
