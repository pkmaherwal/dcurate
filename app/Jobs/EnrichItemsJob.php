<?php

namespace App\Jobs;

use App\Enums\AiStatus;
use App\Models\Item;
use App\Services\Ai\ItemEnrichmentService;
use App\Tenancy\CurrentWorkspace;
use App\Tenancy\MissingWorkspaceException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Enriches a batch of items with AI. Runs inside the items' workspace (restored from Context).
 */
class EnrichItemsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public int $timeout = 170;

    /**
     * @param  list<int>  $itemIds
     */
    public function __construct(public array $itemIds)
    {
        $this->onQueue('ai');
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['workspace:'.app(CurrentWorkspace::class)->id(), 'enrich'];
    }

    public function handle(ItemEnrichmentService $service, CurrentWorkspace $workspace): void
    {
        if (! $workspace->check()) {
            $this->fail(MissingWorkspaceException::forOperation('enrich items without a workspace'));

            return;
        }

        $items = Item::query()
            ->whereKey($this->itemIds)
            ->where('ai_status', AiStatus::Pending->value)
            ->get();

        foreach ($items as $item) {
            if (! $service->enrich($item)) {
                return; // Daily budget reached; the sweeper picks the rest up later.
            }
        }
    }
}
