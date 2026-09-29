<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Daily AI usage per workspace, provider, model and purpose (budget + future billing).
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $date Y-m-d in the workspace timezone (kept as a plain string so lookups match on every database)
 * @property string $provider
 * @property string $model
 * @property string $purpose
 * @property int $requests
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $web_search_calls
 * @property string $est_cost_usd
 */
#[Table('ai_usage')]
#[Fillable(['workspace_id', 'date', 'provider', 'model', 'purpose', 'requests', 'input_tokens', 'output_tokens', 'web_search_calls', 'est_cost_usd'])]
class AiUsage extends Model
{
    use BelongsToWorkspace;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requests' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'web_search_calls' => 'integer',
            'est_cost_usd' => 'decimal:6',
        ];
    }
}
