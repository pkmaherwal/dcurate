<?php

namespace Tests\Fixtures;

use App\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordWorkspaceJob implements ShouldQueue
{
    use Queueable;

    public static ?int $seen = null;

    public function handle(CurrentWorkspace $workspace): void
    {
        static::$seen = $workspace->id();
    }
}
