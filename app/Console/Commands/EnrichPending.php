<?php

namespace App\Console\Commands;

use App\Enums\AiStatus;
use App\Jobs\EnrichItemsJob;
use App\Models\Item;
use App\Models\Workspace;
use App\Services\Ai\AiBudget;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('curation:enrich-pending {--failed : Also retry items whose enrichment failed} {--limit=200 : Maximum items per workspace}')]
#[Description('Queue AI enrichment for items still waiting (e.g. after the daily budget reset)')]
class EnrichPending extends Command
{
    public function handle(AiBudget $budget, CurrentWorkspace $current): int
    {
        $statuses = $this->option('failed') ? [AiStatus::Pending->value, AiStatus::Failed->value] : [AiStatus::Pending->value];
        $total = 0;

        Workspace::query()->where('status', 'active')->each(function (Workspace $workspace) use ($budget, $current, $statuses, &$total): void {
            if (! $budget->allows($workspace)) {
                return;
            }

            $current->run($workspace, function () use ($statuses, &$total): void {
                $ids = Item::query()
                    ->whereIn('ai_status', $statuses)
                    ->where('status', 'pending')
                    ->where('created_at', '<=', now()->subMinutes(10))
                    ->orderByDesc('id')
                    ->limit((int) $this->option('limit'))
                    ->pluck('id');

                Item::query()->whereKey($ids)->where('ai_status', AiStatus::Failed->value)->update(['ai_status' => AiStatus::Pending->value]);

                foreach ($ids->chunk(max(1, (int) config('curation.ai.batch_size', 10))) as $chunk) {
                    EnrichItemsJob::dispatch(array_values($chunk->all()));
                }

                $total += $ids->count();
            });
        });

        $this->components->info("Queued {$total} item(s) for AI enrichment.");

        return self::SUCCESS;
    }
}
