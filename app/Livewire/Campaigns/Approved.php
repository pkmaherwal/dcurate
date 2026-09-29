<?php

namespace App\Livewire\Campaigns;

use App\Enums\ItemStatus;
use App\Models\Campaign;
use App\Models\Item;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Approved')]
class Approved extends Component
{
    use WithPagination;

    public Campaign $campaign;

    public function mount(Campaign $campaign): void
    {
        $this->authorize('view', $campaign);
    }

    /**
     * Exactly what the public API serves, in the same order.
     *
     * @return LengthAwarePaginator<int, Item>
     */
    #[Computed]
    public function items(): LengthAwarePaginator
    {
        return $this->campaign->items()
            ->with(['source', 'reviewer'])
            ->where('status', ItemStatus::Approved->value)
            ->orderByDesc('is_featured')
            ->orderByRaw('coalesce(published_at, fetched_at) desc')
            ->paginate(20);
    }

    public function toggleFeatured(int $id): void
    {
        $item = $this->findItem($id);
        $item->forceFill(['is_featured' => ! $item->is_featured])->save();

        unset($this->items);
    }

    public function unpublish(int $id): void
    {
        $this->findItem($id)->resetReview();

        unset($this->items);
        Flux::toast(__('Unpublished and moved back to review.'));
    }

    protected function findItem(int $id): Item
    {
        $item = $this->campaign->items()->findOrFail($id);
        $this->authorize('review', $item);

        return $item;
    }
}
