<?php

namespace App\Listeners;

use App\Events\ItemsIngested;
use App\Jobs\EnrichItemsJob;

/**
 * New items go straight to the AI queue in small batches.
 */
class QueueEnrichment
{
    public function handle(ItemsIngested $event): void
    {
        foreach (array_chunk($event->itemIds, max(1, (int) config('curation.ai.batch_size', 10))) as $chunk) {
            EnrichItemsJob::dispatch($chunk);
        }
    }
}
