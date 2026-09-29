<?php

namespace App\Services\Ai;

use App\Models\AiUsage;
use App\Models\Workspace;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Tracks AI spend per workspace per day and enforces the daily budget.
 */
class AiBudget
{
    /**
     * The effective daily budget: the workspace setting, capped by the plan.
     */
    public function dailyBudget(Workspace $workspace): float
    {
        $setting = (float) $workspace->setting('ai.daily_budget_usd', 5);
        $planCap = $workspace->limit('ai_daily_budget_usd');

        return $planCap === null ? $setting : min($setting, (float) $planCap);
    }

    public function spentToday(Workspace $workspace): float
    {
        return (float) AiUsage::acrossWorkspaces()
            ->where('workspace_id', $workspace->id)
            ->where('date', $this->today($workspace))
            ->sum('est_cost_usd');
    }

    public function allows(Workspace $workspace): bool
    {
        return $this->spentToday($workspace) < $this->dailyBudget($workspace);
    }

    public function webSearchesToday(Workspace $workspace): int
    {
        return (int) AiUsage::acrossWorkspaces()
            ->where('workspace_id', $workspace->id)
            ->where('date', $this->today($workspace))
            ->sum('web_search_calls');
    }

    public function cost(string $model, Usage $usage, int $webSearches = 0): float
    {
        $pricing = config("curation.ai.pricing.{$model}", ['input' => 0.2, 'output' => 1.25]);

        return ($usage->inputTokens * (float) $pricing['input'] + $usage->outputTokens * (float) $pricing['output']) / 1_000_000
            + $webSearches * (float) config('curation.ai.web_search_cost_per_call', 0.01);
    }

    /**
     * Add one request's usage to today's row (atomic increments, safe under concurrent workers).
     */
    public function record(Workspace $workspace, string $provider, string $model, string $purpose, Usage $usage, int $webSearches = 0): void
    {
        $row = AiUsage::acrossWorkspaces()->createOrFirst([
            'workspace_id' => $workspace->id,
            'date' => $this->today($workspace),
            'provider' => $provider,
            'model' => $model,
            'purpose' => $purpose,
        ]);

        AiUsage::acrossWorkspaces()->whereKey($row->id)->incrementEach([
            'requests' => 1,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'web_search_calls' => $webSearches,
            'est_cost_usd' => $this->cost($model, $usage, $webSearches),
        ]);
    }

    protected function today(Workspace $workspace): string
    {
        return now($workspace->setting('timezone'))->toDateString();
    }
}
