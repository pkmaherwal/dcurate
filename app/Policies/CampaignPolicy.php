<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;
use App\Tenancy\CurrentWorkspace;

class CampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->workspaceRole() !== null;
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return $this->inCurrentWorkspace($campaign) && $user->workspaceRole() !== null;
    }

    public function create(User $user): bool
    {
        $workspace = app(CurrentWorkspace::class)->get();

        if ($workspace === null || ! $user->canManageWorkspace($workspace)) {
            return false;
        }

        $limit = $workspace->limit('campaigns');

        return $limit === null || Campaign::count() < $limit;
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $this->inCurrentWorkspace($campaign) && $user->canManageWorkspace();
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $this->update($user, $campaign);
    }

    /**
     * Reviewers (and above) may approve and reject a campaign's items.
     */
    public function review(User $user, Campaign $campaign): bool
    {
        return $this->view($user, $campaign);
    }

    protected function inCurrentWorkspace(Campaign $campaign): bool
    {
        return $campaign->workspace_id === app(CurrentWorkspace::class)->id();
    }
}
