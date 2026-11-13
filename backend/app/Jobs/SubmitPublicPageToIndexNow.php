<?php

namespace App\Jobs;

use App\Services\Integrations\IndexNow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SubmitPublicPageToIndexNow implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $workspaceId, public int $businessId, public string $url)
    {
        $this->onQueue('default');
    }

    public function handle(IndexNow $indexNow): void
    {
        $indexNow->submitPlatformUrls($this->workspaceId, $this->businessId, [$this->url]);
    }
}
