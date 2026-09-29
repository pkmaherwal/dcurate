<?php

namespace App\Livewire\Campaigns;

use App\Enums\ItemStatus;
use App\Models\Campaign;
use App\Support\Locales;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read Collection<int, Campaign> $campaigns
 * @property-read array<string, string> $templates
 */
#[Title('Campaigns')]
class Index extends Component
{
    public string $name = '';

    public string $description = '';

    public string $template = 'sports_tournaments';

    public string $keywords = '';

    public string $region = 'IN';

    public string $language = 'en-IN';

    public int $freshnessDays = 30;

    /**
     * @return Collection<int, Campaign>
     */
    #[Computed]
    public function campaigns(): Collection
    {
        return Campaign::query()
            ->withCount([
                'sources',
                'items as pending_count' => fn (Builder $query) => $query->where('status', ItemStatus::Pending->value),
                'items as approved_count' => fn (Builder $query) => $query->where('status', ItemStatus::Approved->value),
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function templates(): array
    {
        $templates = [];

        foreach ((array) config('campaign_templates') as $key => $template) {
            $templates[(string) $key] = (string) data_get($template, 'label', $key);
        }

        return $templates;
    }

    public function create(): void
    {
        $this->authorize('create', Campaign::class);

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'template' => ['required', Rule::in(array_keys(config('campaign_templates')))],
            'keywords' => ['nullable', 'string', 'max:500'],
            'region' => ['required', Rule::in(array_keys(Locales::regions()))],
            'language' => ['required', Rule::in(array_keys(Locales::languages()))],
            'freshnessDays' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $campaign = Campaign::create([
            'name' => $this->name,
            'description' => $this->description ?: null,
            ...Campaign::templateAttributes($this->template),
            'keywords' => Str::of($this->keywords)->explode(',')->map(fn ($keyword) => trim($keyword))->filter()->values()->all(),
            'region' => $this->region,
            'language' => $this->language,
            'freshness_days' => $this->freshnessDays,
            'created_by' => Auth::id(),
        ]);

        $this->redirectRoute('campaigns.sources', $campaign, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.campaigns.index');
    }
}
