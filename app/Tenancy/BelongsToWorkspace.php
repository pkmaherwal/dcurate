<?php

namespace App\Tenancy;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned: scoped to, and stamped with, the current workspace.
 *
 * @property int $workspace_id
 * @property-read Workspace $workspace
 */
trait BelongsToWorkspace
{
    /**
     * Boot the trait.
     */
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('workspace_id') === null) {
                $model->setAttribute(
                    'workspace_id',
                    app(CurrentWorkspace::class)->id() ?? throw MissingWorkspaceException::forModel($model),
                );
            }
        });
    }

    /**
     * Get the workspace that owns the model.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Query every workspace's rows. For system code only (scheduler, console, token lookup).
     *
     * @param  Builder<static>  $query
     */
    public function scopeAcrossWorkspaces(Builder $query): void
    {
        $query->withoutGlobalScope(WorkspaceScope::class);
    }
}
