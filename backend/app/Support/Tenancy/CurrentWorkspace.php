<?php

namespace App\Support\Tenancy;

use App\Models\Workspace;
use Closure;

/**
 * Holds the tenant for the current request or job. Tenant-owned models apply a global
 * scope from this, so a query can never silently read another workspace's rows.
 */
class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    public function set(?Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?int
    {
        return $this->workspace?->id;
    }

    public function check(): bool
    {
        return $this->workspace !== null;
    }

    /**
     * Run a callback as a given tenant (used by queued jobs), restoring the previous tenant after.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Workspace $workspace, Closure $callback): mixed
    {
        $previous = $this->workspace;
        $this->workspace = $workspace;
        try {
            return $callback();
        } finally {
            $this->workspace = $previous;
        }
    }
}
