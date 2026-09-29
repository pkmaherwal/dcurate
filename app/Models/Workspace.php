<?php

namespace App\Models;

use App\Enums\WorkspacePlan;
use App\Enums\WorkspaceRole;
use App\Tenancy\WorkspaceScope;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A tenant. Every campaign, source, item and token belongs to one workspace.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int|null $owner_id
 * @property WorkspacePlan $plan
 * @property string $status
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'owner_id', 'plan', 'status', 'settings', 'trial_ends_at'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan' => WorkspacePlan::class,
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace): void {
            $workspace->slug ??= static::uniqueSlug($workspace->name);
        });
    }

    /**
     * Generate a slug that is not yet taken.
     */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    /**
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class)->withoutGlobalScope(WorkspaceScope::class);
    }

    /**
     * @return HasMany<Source, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class)->withoutGlobalScope(WorkspaceScope::class);
    }

    /**
     * @return HasMany<WorkspaceApiToken, $this>
     */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(WorkspaceApiToken::class)->withoutGlobalScope(WorkspaceScope::class);
    }

    /**
     * Add a user to the workspace (or change their role).
     */
    public function addMember(User $user, WorkspaceRole $role): void
    {
        $this->users()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);

        if ($user->current_workspace_id === null) {
            $user->forceFill(['current_workspace_id' => $this->id])->save();
        }
    }

    /**
     * Get the user's role in this workspace, if they are a member.
     */
    public function roleOf(User $user): ?WorkspaceRole
    {
        $role = $this->users()->whereKey($user->id)->value('workspace_user.role');

        return $role === null ? null : WorkspaceRole::from($role);
    }

    /**
     * Read a workspace setting, falling back to config('curation.workspace_defaults').
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get(
            $this->settings ?? [],
            $key,
            config("curation.workspace_defaults.{$key}", $default),
        );
    }

    /**
     * Get a plan limit (null = unlimited).
     */
    public function limit(string $key): int|float|null
    {
        return $this->plan->limit($key);
    }
}
