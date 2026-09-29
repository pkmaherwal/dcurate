<div>
    <x-campaign.nav :campaign="$campaign" current="settings" />

    <form wire:submit="save" class="max-w-3xl space-y-10">
        <section class="space-y-5">
            <flux:heading size="lg">{{ __('General') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Name')" required />
                <flux:select wire:model="status" :label="__('Status')" :description="__('Paused campaigns stop fetching.')">
                    @foreach (App\Enums\CampaignStatus::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:textarea wire:model="description" :label="__('Description')" rows="2" />
            <flux:input wire:model="keywords" :label="__('Keywords')" :description="__('Comma separated. Used by web search discovery.')" />
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:select wire:model="region" :label="__('Region')">
                    @foreach (App\Support\Locales::regions() as $code => $label)
                        <flux:select.option :value="$code">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="language" :label="__('Language')">
                    @foreach (App\Support\Locales::languages() as $code => $label)
                        <flux:select.option :value="$code">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="freshnessDays" type="number" min="1" max="365" :label="__('Ignore items older than (days)')" />
            </div>
        </section>

        <section class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('AI curation') }}</flux:heading>
                <flux:text class="mt-1">{{ __('The AI scores every new item against this brief, writes a summary, picks a category and fills in the fields below.') }}</flux:text>
            </div>

            <flux:textarea wire:model="aiBrief" :label="__('What counts as relevant?')" rows="5"
                :placeholder="__('Describe what you want to collect and what to ignore.')" />

            <flux:textarea wire:model="categories" :label="__('Categories')" :description="__('One per line.')" rows="5" />

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <flux:label>{{ __('Fields to extract') }}</flux:label>
                    <flux:button size="sm" icon="plus" wire:click="addField">{{ __('Add field') }}</flux:button>
                </div>

                @foreach ($fields as $index => $field)
                    <div wire:key="field-{{ $index }}" class="grid items-end gap-2 rounded-lg border border-zinc-200 p-3 sm:grid-cols-[1fr_8rem_2fr_auto] dark:border-zinc-700">
                        <flux:input wire:model="fields.{{ $index }}.label" size="sm" :label="__('Label')" />
                        <flux:select wire:model="fields.{{ $index }}.type" size="sm" :label="__('Type')">
                            @foreach (App\Livewire\Campaigns\Settings::FIELD_TYPES as $value => $label)
                                <flux:select.option :value="$value">{{ __($label) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="fields.{{ $index }}.description" size="sm" :label="__('Hint for the AI')" />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeField({{ $index }})" :aria-label="__('Remove field')" />
                    </div>
                @endforeach
            </div>
        </section>

        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            <flux:button wire:click="testAi" icon="sparkles" wire:loading.attr="disabled" wire:target="testAi">
                <span wire:loading.remove wire:target="testAi">{{ __('Test AI on the latest item') }}</span>
                <span wire:loading wire:target="testAi">{{ __('Asking the AI…') }}</span>
            </flux:button>
        </div>
        <flux:text size="sm">{{ __('Save first; the test uses the saved settings.') }}</flux:text>

        @if ($aiTestError)
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="$aiTestError" />
        @endif

        @if ($aiTest)
            <div class="space-y-2 rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                <flux:text size="sm">{{ __('Original:') }} {{ $aiTestTitle }}</flux:text>
                <div class="flex flex-wrap gap-2">
                    <flux:badge size="sm" color="blue">{{ $aiTest['category'] ?? '—' }}</flux:badge>
                    <flux:badge size="sm" icon="sparkles">{{ $aiTest['relevance'] ?? '?' }}%</flux:badge>
                    <flux:badge size="sm">{{ $aiTest['_model'] ?? '' }}</flux:badge>
                </div>
                <div class="font-semibold">{{ $aiTest['title'] ?? '' }}</div>
                <p>{{ $aiTest['summary'] ?? '' }}</p>
                <flux:text size="sm">{{ $aiTest['reason'] ?? '' }}</flux:text>
                @if (! empty($aiTest['fields']))
                    <pre class="overflow-x-auto rounded bg-zinc-50 p-3 text-xs dark:bg-zinc-900">{{ json_encode($aiTest['fields'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                @endif
            </div>
        @endif
    </form>

    @can('delete', $campaign)
        <section class="mt-14 max-w-3xl rounded-xl border border-red-200 p-5 dark:border-red-900">
            <flux:heading>{{ __('Delete campaign') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Deletes the campaign with all its sources and items. This cannot be undone.') }}</flux:text>
            <flux:button class="mt-4" variant="danger" wire:click="delete" wire:confirm="{{ __('Delete this campaign and everything in it?') }}">{{ __('Delete campaign') }}</flux:button>
        </section>
    @endcan
</div>
