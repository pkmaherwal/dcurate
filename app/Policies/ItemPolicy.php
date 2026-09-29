<?php

namespace App\Policies;

use App\Models\Item;
use App\Models\User;
use App\Tenancy\CurrentWorkspace;

class ItemPolicy
{
    public function view(User $user, Item $item): bool
    {
        return $this->inCurrentWorkspace($item) && $user->workspaceRole() !== null;
    }

    /**
     * Approve, reject or edit an item. Every workspace role may review.
     */
    public function review(User $user, Item $item): bool
    {
        return $this->view($user, $item);
    }

    protected function inCurrentWorkspace(Item $item): bool
    {
        return $item->workspace_id === app(CurrentWorkspace::class)->id();
    }
}
