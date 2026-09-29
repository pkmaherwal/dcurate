<?php

namespace App\Console\Commands;

use App\Jobs\FetchSourceJob;
use App\Models\Source;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('curation:dispatch-due {--limit=500 : Maximum sources to dispatch per run}')]
#[Description('Queue a fetch for every source that is due, across all workspaces')]
class DispatchDueSources extends Command
{
    public function handle(CurrentWorkspace $current): int
    {
        $count = 0;

        Source::acrossWorkspaces()
            ->due()
            ->with('workspace')
            ->orderByRaw('next_fetch_at is null desc')
            ->orderBy('next_fetch_at')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (Source $source) use ($current, &$count): void {
                // A statement, not an arrow function: the job is only queued when the PendingDispatch is
                // destroyed, which must happen while the source's workspace is still the current one.
                $current->run($source->workspace, function () use ($source): void {
                    FetchSourceJob::dispatch($source);
                });

                // Push the next fetch out so a queued (not yet run) source is not dispatched again every minute.
                $source->forceFill(['next_fetch_at' => now()->addMinutes($source->fetch_interval_minutes)])->saveQuietly();

                $count++;
            });

        $this->components->info("Dispatched {$count} source fetch(es).");

        return self::SUCCESS;
    }
}
