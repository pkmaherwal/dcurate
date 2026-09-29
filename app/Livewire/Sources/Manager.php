<?php

namespace App\Livewire\Sources;

use App\Enums\ItemStatus;
use App\Enums\SourceType;
use App\Jobs\FetchSourceJob;
use App\Models\Campaign;
use App\Models\Source;
use App\Services\Curation\SourceFetcher;
use App\Sources\RawItem;
use App\Sources\SourceAdapterFactory;
use App\Sources\SourceException;
use App\Tenancy\CurrentWorkspace;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Title('Sources')]
class Manager extends Component
{
    public Campaign $campaign;

    public ?int $editingId = null;

    public string $type = 'rss';

    public string $target = '';

    public string $name = '';

    public int $interval = 60;

    public bool $isActive = true;

    /**
     * Type-specific settings (website selectors, Google News window, web search domains, Instagram driver).
     *
     * @var array<string, mixed>
     */
    public array $options = [];

    /**
     * @var list<array{title: string, url: string, published: string|null, image: string|null}>
     */
    public array $preview = [];

    public ?string $previewError = null;

    public function mount(Campaign $campaign): void
    {
        $this->authorize('view', $campaign);
    }

    /**
     * @return Collection<int, Source>
     */
    #[Computed]
    public function sources(): Collection
    {
        return $this->campaign->sources()
            ->withCount(['items as pending_count' => fn (Builder $query) => $query->where('status', ItemStatus::Pending->value)])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    /**
     * Interval choices in minutes, limited by the workspace plan.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function intervals(): array
    {
        $min = $this->minInterval();

        return collect([15 => '15 min', 30 => '30 min', 60 => '1 hour', 120 => '2 hours', 360 => '6 hours', 720 => '12 hours', 1440 => '1 day'])
            ->filter(fn ($label, $minutes) => $minutes >= $min)
            ->all();
    }

    public function openCreate(): void
    {
        $this->authorize('create', Source::class);

        $this->resetForm();
        Flux::modal('source-form')->show();
    }

    public function edit(int $id): void
    {
        $source = $this->findSource($id);
        $this->authorize('update', $source);

        $this->resetForm();
        $this->editingId = $source->id;
        $this->type = $source->type->value;
        $this->target = $source->target;
        $this->name = $source->name;
        $this->interval = $source->fetch_interval_minutes;
        $this->isActive = $source->is_active;
        $this->options = $this->optionsFromConfig($source);

        Flux::modal('source-form')->show();
    }

    public function updatedType(): void
    {
        $this->interval = max(SourceType::from($this->type)->defaultInterval(), $this->minInterval());
        $this->options = [];
        $this->resetPreview();
    }

    public function updatedTarget(): void
    {
        $this->resetPreview();
    }

    public function testFetch(SourceAdapterFactory $adapters, SourceFetcher $fetcher): void
    {
        $this->resetPreview();
        $this->validateForm();

        $source = $this->buildSource(new Source(['campaign_id' => $this->campaign->id]));
        $source->setRelation('campaign', $this->campaign);

        try {
            $adapters->for($source)->prepare($source);
            $items = $fetcher->preview($source)->items;
        } catch (SourceException $e) {
            $this->previewError = $e->getMessage();

            return;
        } catch (Throwable $e) {
            report($e);
            $this->previewError = __('Could not fetch: :message', ['message' => Str::limit($e->getMessage(), 200)]);

            return;
        }

        if (blank($this->name)) {
            $this->name = $source->name;
        }

        $this->preview = array_map(fn (RawItem $item) => [
            'title' => $item->title,
            'url' => $item->url,
            'published' => $item->publishedAt?->diffForHumans(),
            'image' => $item->media['image'] ?? null,
        ], array_slice($items, 0, 8));

        if ($this->preview === []) {
            $this->previewError = __('The source works but returned no items right now.');
        }
    }

    public function save(SourceAdapterFactory $adapters): void
    {
        $existing = $this->editingId ? $this->findSource($this->editingId) : null;
        $existing ? $this->authorize('update', $existing) : $this->authorize('create', Source::class);

        $this->validateForm();

        $source = $existing ?? new Source(['campaign_id' => $this->campaign->id]);
        $changed = $source->exists === false
            || $source->type->value !== $this->type
            || $source->target !== trim($this->target);

        $this->buildSource($source, keepResolvedConfig: ! $changed);
        $source->setRelation('campaign', $this->campaign);

        if ($changed) {
            try {
                $adapters->for($source)->prepare($source);
            } catch (SourceException $e) {
                $this->addError('target', $e->getMessage());

                return;
            } catch (Throwable $e) {
                report($e);
                $this->addError('target', __('Could not reach the source: :message', ['message' => Str::limit($e->getMessage(), 160)]));

                return;
            }

            $source->forceFill(['etag' => null, 'last_modified' => null, 'content_hash' => null, 'next_fetch_at' => null]);
        }

        if ($source->is_active && $source->isDirty('is_active')) {
            $source->forceFill(['consecutive_errors' => 0, 'next_fetch_at' => null]);
        }

        $source->name = $source->name ?: Str::limit($source->target, 60);
        $source->save();

        if ($source->is_active && $changed) {
            FetchSourceJob::dispatch($source);
        }

        Flux::modal('source-form')->close();
        Flux::toast(variant: 'success', text: $existing ? __('Source updated.') : __('Source added. The first fetch is on its way.'));

        $this->resetForm();
        unset($this->sources);
    }

    public function toggle(int $id): void
    {
        $source = $this->findSource($id);
        $this->authorize('update', $source);

        $source->is_active = ! $source->is_active;

        if ($source->is_active) {
            $source->forceFill(['consecutive_errors' => 0, 'next_fetch_at' => null]);
        }

        $source->save();
        unset($this->sources);
    }

    public function fetchNow(int $id): void
    {
        $source = $this->findSource($id);
        $this->authorize('update', $source);

        FetchSourceJob::dispatch($source);

        Flux::toast(__('Fetch queued for :name.', ['name' => $source->name]));
    }

    public function delete(int $id): void
    {
        $source = $this->findSource($id);
        $this->authorize('delete', $source);

        $source->delete();

        Flux::toast(__('Source deleted. Items it found are kept.'));
        unset($this->sources);
    }

    protected function findSource(int $id): Source
    {
        return $this->campaign->sources()->findOrFail($id);
    }

    protected function buildSource(Source $source, bool $keepResolvedConfig = false): Source
    {
        $source->type = SourceType::from($this->type);
        $source->target = trim($this->target);
        $source->name = trim($this->name);
        $source->fetch_interval_minutes = $this->interval;
        $source->is_active = $this->isActive;
        $source->config = array_merge($keepResolvedConfig ? ($source->config ?? []) : [], $this->configFromOptions());

        return $source;
    }

    /**
     * @return array<string, mixed>
     */
    protected function configFromOptions(): array
    {
        return match (SourceType::from($this->type)) {
            SourceType::Website => array_filter([
                'selectors' => array_filter(array_map('trim', (array) ($this->options['selectors'] ?? []))) ?: null,
                'render_js' => (bool) ($this->options['render_js'] ?? false),
            ], fn ($value) => $value !== null),
            SourceType::GoogleNews => ['when' => $this->options['when'] ?? '7d'],
            SourceType::WebSearch => ['allowed_domains' => Str::of((string) ($this->options['allowed_domains'] ?? ''))
                ->explode(',')->map(fn ($domain) => trim(preg_replace('#^https?://#', '', trim($domain)) ?? '', '/'))->filter()->values()->all()],
            SourceType::Instagram => array_filter(['driver' => $this->options['driver'] ?? null]),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function optionsFromConfig(Source $source): array
    {
        return match ($source->type) {
            SourceType::Website => [
                'selectors' => $source->option('selectors', []),
                'render_js' => (bool) $source->option('render_js', false),
            ],
            SourceType::GoogleNews => ['when' => $source->option('when', '7d')],
            SourceType::WebSearch => ['allowed_domains' => implode(', ', (array) $source->option('allowed_domains', []))],
            SourceType::Instagram => ['driver' => $source->option('driver')],
            default => [],
        };
    }

    protected function validateForm(): void
    {
        $this->validate([
            'type' => ['required', Rule::enum(SourceType::class)],
            'target' => ['required', 'string', 'max:2000'],
            'name' => ['nullable', 'string', 'max:255'],
            'interval' => ['required', 'integer', 'min:'.$this->minInterval(), 'max:10080'],
            'options.selectors.*' => ['nullable', 'string', 'max:255'],
            'options.when' => ['nullable', Rule::in(['', '1d', '7d', '30d'])],
            'options.allowed_domains' => ['nullable', 'string', 'max:2000'],
            'options.driver' => ['nullable', Rule::in(['official', 'apify'])],
        ]);
    }

    protected function minInterval(): int
    {
        return (int) (app(CurrentWorkspace::class)->get()?->limit('min_fetch_interval') ?? 15);
    }

    protected function resetForm(): void
    {
        $this->reset('editingId', 'type', 'target', 'name', 'isActive', 'options');
        $this->interval = max(SourceType::from($this->type)->defaultInterval(), $this->minInterval());
        $this->resetPreview();
        $this->resetValidation();
    }

    protected function resetPreview(): void
    {
        $this->preview = [];
        $this->previewError = null;
    }
}
