<?php

namespace App\Livewire\Sources;

use App\Enums\FetchRunStatus;
use App\Jobs\FetchSourceJob;
use App\Models\FetchRun;
use App\Models\Source;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Source health')]
class Health extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Source::class);
    }

    /**
     * Every source in the workspace, problems first.
     *
     * @return Collection<int, Source>
     */
    #[Computed]
    public function sources(): Collection
    {
        return Source::query()
            ->with('campaign')
            ->orderByDesc('consecutive_errors')
            ->orderBy('is_active')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, FetchRun>
     */
    #[Computed]
    public function failures(): Collection
    {
        return FetchRun::query()
            ->with('source')
            ->where('status', FetchRunStatus::Failed->value)
            ->latest('started_at')
            ->limit(15)
            ->get();
    }

    public function retry(int $id): void
    {
        $source = Source::findOrFail($id);
        $this->authorize('update', $source);

        $source->forceFill(['is_active' => true, 'consecutive_errors' => 0, 'next_fetch_at' => null])->save();
        FetchSourceJob::dispatch($source);

        unset($this->sources);
        Flux::toast(__('Retry queued for :name.', ['name' => $source->name]));
    }
}
