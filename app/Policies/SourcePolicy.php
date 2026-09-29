<?php

namespace App\Policies;

use App\Models\Source;
use App\Models\User;
use App\Tenancy\CurrentWorkspace;

class SourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->workspaceRole() !== null;
    }

    public function view(User $user, Source $source): bool
    {
        return $this->inCurrentWorkspace($source) && $user->workspaceRole() !== null;
    }

    public function create(User $user): bool
    {
        $workspace = app(CurrentWorkspace::class)->get();

        if ($workspace === null || ! $user->canManageWorkspace($workspace)) {
            return false;
        }

        $limit = $workspace->limit('sources');

        return $limit === null || Source::count() < $limit;
    }

    public function update(User $user, Source $source): bool
    {
        return $this->inCurrentWorkspace($source) && $user->canManageWorkspace();
    }

    public function delete(User $user, Source $source): bool
    {
        return $this->update($user, $source);
    }

    protected function inCurrentWorkspace(Source $source): bool
    {
        return $source->workspace_id === app(CurrentWorkspace::class)->id();
    }
}
