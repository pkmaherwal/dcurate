<?php

namespace App\Services\Curation;

use App\Enums\FetchRunStatus;
use App\Events\ItemsIngested;
use App\Events\SourcePaused;
use App\Models\FetchRun;
use App\Models\Item;
use App\Models\Source;
use App\Sources\FetchResult;
use App\Sources\SourceAdapterFactory;
use App\Sources\SourceException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one fetch for a source: adapter → ingest → health bookkeeping.
 */
class SourceFetcher
{
    public function __construct(protected SourceAdapterFactory $adapters, protected ItemIngestor $ingestor)
    {
        //
    }

    public function fetch(Source $source): FetchRun
    {
        $run = FetchRun::create([
            'source_id' => $source->id,
            'status' => FetchRunStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $result = $this->adapters->for($source)->fetch($source, conditional: true);

            $this->remember($source, $result);

            if ($result->notModified) {
                $run->update(['status' => FetchRunStatus::NotModified, 'finished_at' => now()]);
                $source->markSucceeded(FetchRunStatus::NotModified);

                return $run;
            }

            $created = $this->ingestor->ingest($source, $result->items);

            $run->update([
                'status' => FetchRunStatus::Success,
                'items_found' => count($result->items),
                'items_new' => $created->count(),
                'finished_at' => now(),
            ]);

            $source->markSucceeded();

            if ($created->isNotEmpty()) {
                ItemsIngested::dispatch($source, array_values($created->map(fn (Item $item): int => $item->id)->all()));
            }
        } catch (Throwable $e) {
            $message = $e instanceof SourceException ? $e->getMessage() : class_basename($e).': '.$e->getMessage();

            Log::warning('Source fetch failed', ['source_id' => $source->id, 'error' => $message]);

            $run->update(['status' => FetchRunStatus::Failed, 'error' => $message, 'finished_at' => now()]);

            if ($source->markFailed($message)) {
                SourcePaused::dispatch($source, $message);
            }
        }

        return $run;
    }

    /**
     * Fetch without storing anything ("Test fetch" in the UI).
     */
    public function preview(Source $source): FetchResult
    {
        return $this->adapters->for($source)->fetch($source, conditional: false);
    }

    protected function remember(Source $source, FetchResult $result): void
    {
        if ($result->configUpdates !== []) {
            $source->mergeConfig($result->configUpdates);
        }

        $source->forceFill(array_intersect_key($result->sourceUpdates, array_flip(['etag', 'last_modified', 'content_hash'])));
    }
}
