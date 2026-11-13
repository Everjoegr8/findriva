<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

abstract class Controller
{
    protected function workspace(): Workspace
    {
        return app(CurrentWorkspace::class)->get() ?? throw new AccessDeniedHttpException('No workspace selected.');
    }

    /** Role check in the active workspace. */
    protected function authorizePermission(Permission $permission): void
    {
        if (! request()->user()?->canIn($this->workspace(), $permission)) {
            throw new AccessDeniedHttpException('Your role does not allow this action.');
        }
    }

    /**
     * Defence in depth: even though queries are tenant-scoped, confirm the record belongs to the
     * active workspace before acting on it. Returns 404 so other tenants' ids are not revealed.
     */
    protected function ensureInWorkspace(Model ...$models): void
    {
        foreach ($models as $model) {
            if ((int) $model->getAttribute('workspace_id') !== $this->workspace()->id) {
                throw new NotFoundHttpException;
            }
        }
    }

    /** Ensures a child record belongs to the given parent business. */
    protected function ensureBelongs(Model $child, Model $business): void
    {
        $this->ensureInWorkspace($child, $business);
        if ((int) $child->getAttribute('business_id') !== (int) $business->getKey()) {
            throw new NotFoundHttpException;
        }
    }
}
