<?php

namespace App\Listeners;

use App\Enums\WorkspacePlan;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Events\Registered;

/**
 * Gives every self-registered user their own workspace (used once public sign-up is enabled).
 */
class CreatePersonalWorkspace
{
    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || $user->workspaces()->exists()) {
            return;
        }

        $workspace = Workspace::create([
            'name' => __(':name\'s workspace', ['name' => $user->name]),
            'owner_id' => $user->id,
            'plan' => WorkspacePlan::Free,
        ]);

        $workspace->addMember($user, WorkspaceRole::Owner);
    }
}
