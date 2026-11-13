<?php

namespace App\Models\Concerns;

use App\Models\Business;
use App\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Tenant isolation at the data layer. When a tenant is active every query is scoped to it,
 * and new rows are stamped with it. A row can only be created without an active tenant when
 * its workspace can be derived from its parent business.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('workspace', function (Builder $builder) {
            $tenant = app(CurrentWorkspace::class);
            if ($tenant->check()) {
                $builder->where($builder->getModel()->qualifyColumn('workspace_id'), $tenant->id());
            }
        });

        static::creating(function ($model) {
            $tenant = app(CurrentWorkspace::class);
            if (empty($model->workspace_id) && ! empty($model->business_id)) {
                $model->workspace_id = Business::withoutGlobalScopes()->whereKey($model->business_id)->value('workspace_id');
            }
            if (empty($model->workspace_id) && $tenant->check()) {
                $model->workspace_id = $tenant->id();
            }
            if (empty($model->workspace_id)) {
                throw new LogicException('Cannot create '.static::class.' without a workspace.');
            }
            if ($tenant->check() && (int) $model->workspace_id !== $tenant->id()) {
                throw new LogicException('Cannot create '.static::class.' in a workspace other than the active tenant.');
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
