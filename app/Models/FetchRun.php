<?php

namespace App\Models;

use App\Enums\FetchRunStatus;
use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One fetch attempt for a source: the source health log.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $source_id
 * @property FetchRunStatus $status
 * @property int $items_found
 * @property int $items_new
 * @property string|null $error
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
#[Fillable(['source_id', 'status', 'items_found', 'items_new', 'error', 'started_at', 'finished_at'])]
#[WithoutTimestamps]
class FetchRun extends Model
{
    use BelongsToWorkspace, MassPrunable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FetchRunStatus::class,
            'items_found' => 'integer',
            'items_new' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Keep 30 days of fetch history (pruned across all workspaces).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return $this->newQueryWithoutScopes()->where('started_at', '<', now()->subDays(30));
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
