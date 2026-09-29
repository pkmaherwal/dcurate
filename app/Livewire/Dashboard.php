<?php

namespace App\Livewire;

use App\Enums\FetchRunStatus;
use App\Enums\ItemStatus;
use App\Models\AiUsage;
use App\Models\Campaign;
use App\Models\FetchRun;
use App\Models\Item;
use App\Models\Source;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    /**
     * @return array{pending: int, approved_today: int, failing_sources: int, ai_cost_month: float}
     */
    #[Computed]
    public function stats(): array
    {
        return [
            'pending' => Item::where('status', ItemStatus::Pending->value)->count(),
            'approved_today' => Item::where('status', ItemStatus::Approved->value)->where('reviewed_at', '>=', now()->startOfDay())->count(),
            'failing_sources' => Source::where('consecutive_errors', '>', 0)->count(),
            'ai_cost_month' => (float) AiUsage::where('date', '>=', now()->startOfMonth()->toDateString())->sum('est_cost_usd'),
        ];
    }

    /**
     * @return Collection<int, Campaign>
     */
    #[Computed]
    public function campaigns(): Collection
    {
        return Campaign::query()
            ->withCount(['items as pending_count' => fn (Builder $query) => $query->where('status', ItemStatus::Pending->value)])
            ->orderByDesc('pending_count')
            ->limit(8)
            ->get();
    }

    /**
     * @return Collection<int, FetchRun>
     */
    #[Computed]
    public function recentRuns(): Collection
    {
        return FetchRun::query()->with('source')->latest('started_at')->limit(10)->get();
    }

    public function statusColor(FetchRunStatus $status): string
    {
        return $status->color();
    }
}
