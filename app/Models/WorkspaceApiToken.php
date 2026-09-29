<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An API token that lets a website read a workspace's approved items.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $token
 * @property array<int, string>|null $abilities
 * @property int|null $created_by
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'token', 'abilities', 'created_by', 'expires_at'])]
#[Hidden(['token'])]
class WorkspaceApiToken extends Model
{
    use BelongsToWorkspace;

    public const PREFIX = 'dc_';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Create a token for the current workspace. Returns the model and the plain-text token (shown once).
     *
     * @return array{0: WorkspaceApiToken, 1: string}
     */
    public static function issue(string $name, ?User $creator = null, ?CarbonInterface $expiresAt = null): array
    {
        $plain = self::PREFIX.Str::random(40);

        $token = static::create([
            'name' => $name,
            'token' => hash('sha256', $plain),
            'abilities' => ['read'],
            'created_by' => $creator?->id,
            'expires_at' => $expiresAt,
        ]);

        return [$token, $plain];
    }

    /**
     * Find a valid token by its plain-text value, across all workspaces.
     */
    public static function findValid(string $plain): ?self
    {
        return static::acrossWorkspaces()
            ->where('token', hash('sha256', $plain))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
