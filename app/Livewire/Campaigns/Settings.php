<?php

namespace App\Livewire\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\Ai\ItemEnrichmentService;
use App\Support\Locales;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Title('Campaign settings')]
class Settings extends Component
{
    public const FIELD_TYPES = ['string' => 'Text', 'date' => 'Date', 'number' => 'Number', 'list' => 'List'];

    public Campaign $campaign;

    public string $name = '';

    public string $description = '';

    public string $status = 'active';

    public string $keywords = '';

    public string $region = 'IN';

    public string $language = 'en-IN';

    public int $freshnessDays = 30;

    public string $aiBrief = '';

    /** One category per line. */
    public string $categories = '';

    /**
     * @var array<int, array{key: string, label: string, type: string, description: string}>
     */
    public array $fields = [];

    /**
     * Result of the last "Test AI" run.
     *
     * @var array<string, mixed>|null
     */
    public ?array $aiTest = null;

    public ?string $aiTestTitle = null;

    public ?string $aiTestError = null;

    public function mount(Campaign $campaign): void
    {
        $this->authorize('update', $campaign);

        $this->name = $campaign->name;
        $this->description = (string) $campaign->description;
        $this->status = $campaign->status->value;
        $this->keywords = implode(', ', $campaign->keywords ?? []);
        $this->region = $campaign->region;
        $this->language = $campaign->language;
        $this->freshnessDays = $campaign->freshness_days;
        $this->aiBrief = (string) $campaign->ai_brief;
        $this->categories = implode("\n", $campaign->categories ?? []);
        $this->fields = array_map(fn (array $field) => [
            'key' => $field['key'],
            'label' => $field['label'],
            'type' => $field['type'],
            'description' => $field['description'] ?? '',
        ], $campaign->extraction_fields ?? []);
    }

    public function addField(): void
    {
        $this->fields[] = ['key' => '', 'label' => '', 'type' => 'string', 'description' => ''];
    }

    public function removeField(int $index): void
    {
        unset($this->fields[$index]);
        $this->fields = array_values($this->fields);
    }

    public function save(): void
    {
        $this->authorize('update', $this->campaign);

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::enum(CampaignStatus::class)],
            'keywords' => ['nullable', 'string', 'max:500'],
            'region' => ['required', Rule::in(array_keys(Locales::regions()))],
            'language' => ['required', Rule::in(array_keys(Locales::languages()))],
            'freshnessDays' => ['required', 'integer', 'min:1', 'max:365'],
            'aiBrief' => ['nullable', 'string', 'max:3000'],
            'categories' => ['nullable', 'string', 'max:2000'],
            'fields' => ['array', 'max:20'],
            'fields.*.label' => ['required', 'string', 'max:60'],
            'fields.*.type' => ['required', Rule::in(array_keys(self::FIELD_TYPES))],
            'fields.*.description' => ['nullable', 'string', 'max:200'],
        ]);

        $fields = collect($this->fields)
            ->map(fn (array $field) => [
                'key' => Str::snake(Str::ascii($field['key'] ?: $field['label'])) ?: 'field',
                'label' => trim($field['label']),
                'type' => $field['type'],
                'description' => trim($field['description']),
            ])
            ->unique('key')
            ->values()
            ->all();

        $this->campaign->update([
            'name' => $this->name,
            'description' => $this->description ?: null,
            'status' => $this->status,
            'keywords' => Str::of($this->keywords)->explode(',')->map(fn ($keyword) => trim($keyword))->filter()->values()->all(),
            'region' => $this->region,
            'language' => $this->language,
            'freshness_days' => $this->freshnessDays,
            'ai_brief' => $this->aiBrief ?: null,
            'categories' => Str::of($this->categories)->explode("\n")->map(fn ($category) => trim($category))->filter()->unique()->values()->all(),
            'extraction_fields' => $fields,
        ]);

        $this->fields = $fields;

        Flux::toast(variant: 'success', text: __('Campaign saved.'));
    }

    /**
     * Run the AI (with the saved settings) on the newest item, without changing it.
     */
    public function testAi(ItemEnrichmentService $enrichment): void
    {
        $this->authorize('update', $this->campaign);
        $this->reset('aiTest', 'aiTestTitle', 'aiTestError');

        $item = $this->campaign->items()->latest('id')->first();

        if ($item === null) {
            $this->aiTestError = __('Add a source and wait for the first items, then test again.');

            return;
        }

        try {
            $this->aiTest = $enrichment->preview($item);
            $this->aiTestTitle = $item->title;
        } catch (Throwable $e) {
            report($e);
            $this->aiTestError = Str::limit($e->getMessage(), 300);
        }
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->campaign);

        $this->campaign->delete();

        $this->redirectRoute('campaigns.index', navigate: true);
    }
}
