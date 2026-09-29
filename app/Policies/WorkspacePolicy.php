<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $user->belongsToWorkspace($workspace);
    }

    /**
     * Manage settings, members, API tokens and AI configuration.
     */
    public function manage(User $user, Workspace $workspace): bool
    {
        return $user->canManageWorkspace($workspace);
    }
}
