<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Services\Curation\SourceFetcher;
use App\Sources\RawItem;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

#[Signature('curation:fetch {source : Source id} {--dry : Preview items without storing them}')]
#[Description('Fetch one source now (for debugging)')]
class FetchSource extends Command
{
    public function handle(SourceFetcher $fetcher, CurrentWorkspace $current): int
    {
        $source = Source::acrossWorkspaces()->with('workspace')->find($this->argument('source'));

        if ($source === null) {
            $this->components->error('Source not found.');

            return self::FAILURE;
        }

        return $current->run($source->workspace, function () use ($source, $fetcher) {
            $this->components->info("Fetching [{$source->type->shortLabel()}] {$source->name}");

            if ($this->option('dry')) {
                try {
                    $items = $fetcher->preview($source)->items;
                } catch (Throwable $e) {
                    $this->components->error($e->getMessage());

                    return self::FAILURE;
                }

                $this->table(['Published', 'Title', 'URL'], array_map(fn (RawItem $item) => [
                    $item->publishedAt?->toDateTimeString() ?? '—',
                    Str::limit($item->title, 60),
                    Str::limit($item->url, 60),
                ], $items));

                return self::SUCCESS;
            }

            $run = $fetcher->fetch($source);

            $this->components->twoColumnDetail('Status', $run->status->value);
            $this->components->twoColumnDetail('Items found', (string) $run->items_found);
            $this->components->twoColumnDetail('New items', (string) $run->items_new);

            if ($run->error) {
                $this->components->error($run->error);
            }

            return $run->error ? self::FAILURE : self::SUCCESS;
        });
    }
}
