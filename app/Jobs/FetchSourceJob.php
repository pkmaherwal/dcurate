<?php

namespace App\Jobs;

use App\Models\Source;
use App\Services\Curation\SourceFetcher;
use App\Tenancy\CurrentWorkspace;
use App\Tenancy\MissingWorkspaceException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Fetches one source. Runs inside the source's workspace (restored from Context).
 */
class FetchSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 170;

    public int $uniqueFor = 900;

    public function __construct(public Source $source)
    {
        $this->onQueue('fetch');
    }

    public function uniqueId(): string
    {
        return (string) $this->source->id;
    }

    /**
     * Keep retrying while rate limited; fetch errors are recorded on the source, not thrown.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited('source-fetch')];
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['workspace:'.$this->source->workspace_id, 'source:'.$this->source->id];
    }

    public function handle(SourceFetcher $fetcher, CurrentWorkspace $workspace): void
    {
        if ($workspace->id() !== $this->source->workspace_id) {
            $this->fail(MissingWorkspaceException::forOperation("fetch source {$this->source->id} outside its workspace"));

            return;
        }

        $fetcher->fetch($this->source);
    }
}
