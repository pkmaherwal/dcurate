<?php

namespace App\Listeners;

use App\Enums\WorkspaceRole;
use App\Events\SourcePaused;
use App\Notifications\SourcePausedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the workspace's owners and admins when a source stops being fetched.
 */
class NotifySourcePaused
{
    public function handle(SourcePaused $event): void
    {
        $managers = $event->source->workspace->users()
            ->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])
            ->get();

        Notification::send($managers, new SourcePausedNotification($event->source, $event->error));
    }
}
