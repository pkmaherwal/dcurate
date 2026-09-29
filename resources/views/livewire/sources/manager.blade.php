<div>
    <x-campaign.nav :campaign="$campaign" current="sources" />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <flux:text>{{ __('Where this campaign looks for updates. New items land in the review queue.') }}</flux:text>

        @can('create', App\Models\Source::class)
            <flux:button variant="primary" icon="plus" wire:click="openCreate">{{ __('Add source') }}</flux:button>
        @endcan
    </div>

    @if ($this->sources->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
            <flux:icon.rss class="mx-auto size-8 text-zinc-400" />
            <flux:heading class="mt-3">{{ __('No sources yet') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Add a news site, federation website, YouTube channel, Instagram account or search.') }}</flux:text>
        </div>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Source') }}</flux:table.column>
                <flux:table.column>{{ __('Every') }}</flux:table.column>
                <flux:table.column>{{ __('Last fetch') }}</flux:table.column>
                <flux:table.column align="end">{{ __('To review') }}</flux:table.column>
                <flux:table.column>{{ __('Active') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->sources as $source)
                    <flux:table.row :key="$source->id">
                        <flux:table.cell class="max-w-md whitespace-normal">
                            <div class="flex items-start gap-3">
                                <flux:icon :name="$source->type->icon()" class="mt-0.5 size-5 shrink-0 text-zinc-400" />
                                <div class="min-w-0">
                                    <div class="font-medium text-zinc-800 dark:text-white">{{ $source->name }}</div>
                                    <div class="truncate text-xs text-zinc-500">{{ $source->type->shortLabel() }} · {{ $source->target }}</div>
                                    @if ($source->last_error)
                                        <div class="mt-1 text-xs text-red-600 dark:text-red-400">{{ Str::limit($source->last_error, 160) }}</div>
                                    @endif
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $this->intervals[$source->fetch_interval_minutes] ?? $source->fetch_interval_minutes.' min' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($source->last_fetched_at)
                                <div class="flex items-center gap-2">
                                    <flux:badge size="sm" :color="App\Enums\FetchRunStatus::tryFrom((string) $source->last_status)?->color() ?? 'zinc'">
                                        {{ Str::headline((string) $source->last_status) }}
                                    </flux:badge>
                                    <span class="text-xs text-zinc-500">{{ $source->last_fetched_at->diffForHumans() }}</span>
                                </div>
                            @else
                                <span class="text-xs text-zinc-500">{{ __('Not yet') }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ $source->pending_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:switch :checked="$source->is_active" wire:click="toggle({{ $source->id }})" :disabled="auth()->user()->cannot('update', $source)" />
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            @can('update', $source)
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="edit({{ $source->id }})">{{ __('Edit') }}</flux:menu.item>
                                        <flux:menu.item icon="arrow-path" wire:click="fetchNow({{ $source->id }})">{{ __('Fetch now') }}</flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item variant="danger" icon="trash" wire:click="delete({{ $source->id }})" wire:confirm="{{ __('Delete this source? Items it already found are kept.') }}">{{ __('Delete') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endcan
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="source-form" class="w-full max-w-2xl">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? __('Edit source') : __('Add source') }}</flux:heading>

            <flux:select wire:model.live="type" :label="__('Type')">
                @foreach (App\Enums\SourceType::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @php($selectedType = App\Enums\SourceType::from($type))

            <flux:input wire:model.blur="target" :label="$selectedType->isUrlBased() ? __('URL') : __('Target')" :description="$selectedType->targetHint()" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Name')" :placeholder="__('Detected automatically')" />
                <flux:select wire:model="interval" :label="__('Check every')">
                    @foreach ($this->intervals as $minutes => $label)
                        <flux:select.option :value="$minutes">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @if ($selectedType === App\Enums\SourceType::Website)
                <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:text>{{ __('Optional CSS selectors. Leave empty and the AI reads the page for you.') }}</flux:text>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input wire:model="options.selectors.item" size="sm" :label="__('Item')" placeholder=".news-list article" />
                        <flux:input wire:model="options.selectors.title" size="sm" :label="__('Title')" placeholder="h3" />
                        <flux:input wire:model="options.selectors.link" size="sm" :label="__('Link')" placeholder="a" />
                        <flux:input wire:model="options.selectors.date" size="sm" :label="__('Date')" placeholder="time" />
                    </div>
                    <flux:switch wire:model="options.render_js" :label="__('Page needs JavaScript (render in a headless browser)')" />
                </div>
            @elseif ($selectedType === App\Enums\SourceType::GoogleNews)
                <flux:select wire:model="options.when" :label="__('Only articles from the last')">
                    <flux:select.option value="1d">{{ __('Day') }}</flux:select.option>
                    <flux:select.option value="7d">{{ __('Week') }}</flux:select.option>
                    <flux:select.option value="30d">{{ __('Month') }}</flux:select.option>
                </flux:select>
            @elseif ($selectedType === App\Enums\SourceType::WebSearch)
                <flux:input wire:model="options.allowed_domains" :label="__('Only these sites (optional)')" :description="__('Comma separated, e.g. the-aiff.com, khelnow.com')" />
            @elseif ($selectedType === App\Enums\SourceType::Instagram)
                <flux:select wire:model="options.driver" :label="__('Fetch with')">
                    <flux:select.option value="">{{ __('Workspace default') }}</flux:select.option>
                    <flux:select.option value="official">{{ __('Official API (Business/Creator accounts)') }}</flux:select.option>
                    <flux:select.option value="apify">{{ __('Apify (any public account, paid)') }}</flux:select.option>
                </flux:select>
            @endif

            <flux:switch wire:model="isActive" :label="__('Active')" />

            @if ($previewError)
                <flux:callout variant="warning" icon="exclamation-triangle" :heading="$previewError" />
            @endif

            @if ($preview)
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <div class="border-b border-zinc-200 px-4 py-2 text-sm font-medium dark:border-zinc-700">{{ __('Latest items (preview)') }}</div>
                    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($preview as $item)
                            <li class="flex items-center gap-3 px-4 py-2" wire:key="preview-{{ $loop->index }}">
                                @if ($item['image'])
                                    <img src="{{ $item['image'] }}" alt="" class="size-10 shrink-0 rounded object-cover" loading="lazy">
                                @endif
                                <div class="min-w-0">
                                    <a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer" class="line-clamp-1 text-sm font-medium hover:underline">{{ $item['title'] }}</a>
                                    <div class="text-xs text-zinc-500">{{ $item['published'] ?? __('No date') }}</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex flex-wrap justify-between gap-2">
                <flux:button wire:click="testFetch" icon="beaker" wire:loading.attr="disabled" wire:target="testFetch">
                    <span wire:loading.remove wire:target="testFetch">{{ __('Test fetch') }}</span>
                    <span wire:loading wire:target="testFetch">{{ __('Fetching…') }}</span>
                </flux:button>
                <div class="flex gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">{{ __('Save') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
</div>
