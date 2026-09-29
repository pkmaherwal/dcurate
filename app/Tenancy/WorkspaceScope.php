<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts tenant models to the current workspace.
 *
 * Fails closed: querying a tenant model with no current workspace throws
 * instead of silently returning every tenant's rows.
 *
 * @implements Scope<Model>
 */
class WorkspaceScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = app(CurrentWorkspace::class)->id()
            ?? throw MissingWorkspaceException::forModel($model);

        $builder->where($model->qualifyColumn('workspace_id'), $workspaceId);
    }
}
