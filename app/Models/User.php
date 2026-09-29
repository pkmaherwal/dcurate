<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use App\Tenancy\CurrentWorkspace;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_workspace_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace|null $currentWorkspace
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * Roles resolved during this request, keyed by workspace id.
     *
     * @var array<int, WorkspaceRole|null>
     */
    protected array $resolvedWorkspaceRoles = [];

    /**
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    /**
     * Get the user's role in the given workspace (defaults to the current workspace).
     */
    public function workspaceRole(?Workspace $workspace = null): ?WorkspaceRole
    {
        $workspace ??= app(CurrentWorkspace::class)->get();

        if ($workspace === null) {
            return null;
        }

        return $this->resolvedWorkspaceRoles[$workspace->id] ??= $workspace->roleOf($this);
    }

    public function belongsToWorkspace(Workspace $workspace): bool
    {
        return $this->workspaceRole($workspace) !== null;
    }

    /**
     * Whether the user may manage campaigns, sources, members and tokens in the current workspace.
     */
    public function canManageWorkspace(?Workspace $workspace = null): bool
    {
        return $this->workspaceRole($workspace)?->canManage() ?? false;
    }

    /**
     * Switch the user's current workspace, if they belong to it.
     */
    public function switchWorkspace(Workspace $workspace): bool
    {
        if (! $this->belongsToWorkspace($workspace)) {
            return false;
        }

        $this->forceFill(['current_workspace_id' => $workspace->id])->save();
        $this->setRelation('currentWorkspace', $workspace);

        return true;
    }
}
