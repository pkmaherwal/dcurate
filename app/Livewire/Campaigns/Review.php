<?php

namespace App\Livewire\Campaigns;

use App\Enums\AiStatus;
use App\Enums\ItemStatus;
use App\Enums\SourceType;
use App\Jobs\EnrichItemsJob;
use App\Models\Campaign;
use App\Models\Item;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * @property-read LengthAwarePaginator<int, Item> $items
 * @property-read array{pending: int, low: int, rejected: int} $counts
 * @property-read Collection<int, \Illuminate\Database\Eloquent\Collection<int, Item>> $duplicates
 * @property-read array<string, string> $fieldLabels
 */
#[Title('Review')]
class Review extends Component
{
    use WithPagination;

    public const REJECT_REASONS = ['Not relevant', 'Duplicate', 'Outdated', 'Low quality', 'Wrong information'];

    public Campaign $campaign;

    /** pending | low | rejected */
    #[Url]
    public string $tab = 'pending';

    #[Url]
    public string $type = '';

    #[Url]
    public string $category = '';

    #[Url(as: 'q')]
    public string $search = '';

    /**
     * @var list<int>
     */
    public array $selected = [];

    public ?int $editingId = null;

    public string $editTitle = '';

    public string $editSummary = '';

    public function mount(Campaign $campaign): void
    {
        $this->authorize('review', $campaign);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'type', 'category', 'search'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    /**
     * @return LengthAwarePaginator<int, Item>
     */
    #[Computed]
    public function items(): LengthAwarePaginator
    {
        return $this->tabQuery($this->tab)
            ->with('source')
            ->when($this->type !== '', fn (Builder $query) => $query->whereHas('source', fn (Builder $q) => $q->where('type', $this->type)))
            ->when($this->category !== '', fn (Builder $query) => $query->where('ai_category', $this->category))
            ->when($this->search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('title', 'like', "%{$this->search}%")
                ->orWhere('ai_title', 'like', "%{$this->search}%")
                ->orWhere('ai_summary', 'like', "%{$this->search}%")))
            ->orderByRaw('coalesce(published_at, fetched_at) desc')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * @return array{pending: int, low: int, rejected: int}
     */
    #[Computed]
    public function counts(): array
    {
        return [
            'pending' => $this->tabQuery('pending')->count(),
            'low' => $this->tabQuery('low')->count(),
            'rejected' => $this->tabQuery('rejected')->count(),
        ];
    }

    /**
     * Other reports of the same stories shown on this page, keyed by cluster id.
     *
     * @return Collection<int, \Illuminate\Database\Eloquent\Collection<int, Item>>
     */
    #[Computed]
    public function duplicates(): Collection
    {
        $clusterIds = array_values(array_unique(array_filter(array_map(fn (Item $item) => $item->cluster_id, $this->items->items()))));

        if ($clusterIds === []) {
            return new Collection;
        }

        return Item::query()
            ->with('source')
            ->whereIn('cluster_id', $clusterIds)
            ->whereColumn('id', '!=', 'cluster_id')
            ->get()
            ->groupBy('cluster_id');
    }

    /**
     * Extraction field labels keyed by field key.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function fieldLabels(): array
    {
        return collect($this->campaign->extraction_fields ?? [])->mapWithKeys(fn (array $field) => [$field['key'] => $field['label']])->all();
    }

    public function approve(int $id): void
    {
        $item = $this->findItem($id);
        $item->approve(Auth::user());

        $this->afterReview(__('Approved: :title', ['title' => str($item->displayTitle())->limit(60)]));
    }

    public function reject(int $id, string $reason = ''): void
    {
        $item = $this->findItem($id);
        $item->reject(Auth::user(), $reason ?: null);

        $this->afterReview(__('Rejected: :title', ['title' => str($item->displayTitle())->limit(60)]));
    }

    public function restore(int $id): void
    {
        $this->findItem($id)->resetReview();

        $this->afterReview(__('Moved back to the review queue.'));
    }

    public function retryAi(int $id): void
    {
        $item = $this->findItem($id);
        $item->forceFill(['ai_status' => AiStatus::Pending])->save();

        EnrichItemsJob::dispatch([$item->id]);

        $this->afterReview(__('Sent to the AI again.'));
    }

    public function startEdit(int $id): void
    {
        $item = $this->findItem($id);

        $this->editingId = $item->id;
        $this->editTitle = $item->displayTitle();
        $this->editSummary = (string) $item->displaySummary();

        Flux::modal('edit-item')->show();
    }

    public function saveEdit(bool $approve = true): void
    {
        $this->validate([
            'editTitle' => ['required', 'string', 'max:500'],
            'editSummary' => ['nullable', 'string', 'max:5000'],
        ]);

        $item = $this->findItem((int) $this->editingId);

        if ($approve) {
            $item->approve(Auth::user(), $this->editTitle, $this->editSummary);
        } else {
            $item->forceFill(['edited_title' => $this->editTitle, 'edited_summary' => $this->editSummary ?: null])->save();
        }

        Flux::modal('edit-item')->close();
        $this->reset('editingId', 'editTitle', 'editSummary');
        $this->afterReview($approve ? __('Edited and approved.') : __('Changes saved.'));
    }

    public function bulk(string $action, string $reason = ''): void
    {
        $items = $this->campaign->items()->whereKey($this->selected)->get();

        foreach ($items as $item) {
            $this->authorize('review', $item);

            match ($action) {
                'approve' => $item->approve(Auth::user()),
                'reject' => $item->reject(Auth::user(), $reason ?: null),
                'restore' => $item->resetReview(),
                default => null,
            };
        }

        $this->selected = [];
        $this->afterReview(trans_choice(':count item updated.|:count items updated.', $items->count()));
    }

    public function toggleSelectPage(): void
    {
        $ids = $this->pageIds();

        $this->selected = array_diff($ids, $this->selected) === [] ? [] : array_values(array_unique([...$this->selected, ...$ids]));
    }

    /**
     * @return Builder<Item>|HasMany<Item, Campaign>
     */
    protected function tabQuery(string $tab): Builder|HasMany
    {
        $threshold = (int) config('curation.ai.relevance_threshold', 30);
        $query = $this->campaign->items();

        return match ($tab) {
            'rejected' => $query->where('status', ItemStatus::Rejected->value),
            'low' => $query->where('status', ItemStatus::Pending->value)
                ->where('ai_relevance', '<', $threshold)
                ->where(fn (Builder $q) => $q->whereNull('cluster_id')->orWhereColumn('cluster_id', 'id')),
            default => $query->where('status', ItemStatus::Pending->value)
                ->where(fn (Builder $q) => $q->whereNull('ai_relevance')->orWhere('ai_relevance', '>=', $threshold))
                ->where(fn (Builder $q) => $q->whereNull('cluster_id')->orWhereColumn('cluster_id', 'id')),
        };
    }

    /**
     * @return list<int>
     */
    protected function pageIds(): array
    {
        return array_values(array_map(fn (Item $item) => $item->id, $this->items->items()));
    }

    protected function findItem(int $id): Item
    {
        $item = $this->campaign->items()->findOrFail($id);
        $this->authorize('review', $item);

        return $item;
    }

    protected function afterReview(string $message): void
    {
        unset($this->items, $this->counts, $this->duplicates);
        $this->selected = array_values(array_intersect($this->selected, $this->pageIds()));

        Flux::toast($message);
    }

    /**
     * @return array<string, string>
     */
    public function sourceTypes(): array
    {
        return collect(SourceType::cases())->mapWithKeys(fn (SourceType $type) => [$type->value => $type->shortLabel()])->all();
    }
}
