<div
    x-data="{
        index: -1,
        cards() { return [...$root.querySelectorAll('[data-review-card]')] },
        focusCard(i) {
            const cards = this.cards();
            if (! cards.length) return;
            this.index = Math.max(0, Math.min(i, cards.length - 1));
            cards[this.index].focus();
            cards[this.index].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        },
        currentId() { return Number(this.cards()[this.index]?.dataset.id) || null },
        handle(e) {
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.target.isContentEditable || e.metaKey || e.ctrlKey || e.altKey) return;
            const id = this.currentId();
            if (e.key === 'j') { e.preventDefault(); this.focusCard(this.index + 1) }
            else if (e.key === 'k') { e.preventDefault(); this.focusCard(this.index - 1) }
            else if (e.key === 'a' && id && $wire.tab !== 'rejected') { e.preventDefault(); $wire.approve(id) }
            else if (e.key === 'r' && id && $wire.tab !== 'rejected') { e.preventDefault(); $wire.reject(id, 'Not relevant') }
            else if (e.key === 'e' && id) { e.preventDefault(); $wire.startEdit(id) }
            else if (e.key === 'o' && id) { window.open(this.cards()[this.index].dataset.url, '_blank', 'noopener') }
        },
    }"
    @keydown.window="handle($event)"
>
    <x-campaign.nav :campaign="$campaign" current="review" />

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <flux:navbar class="-my-2">
            <flux:navbar.item wire:click="$set('tab', 'pending')" :current="$tab === 'pending'" :badge="$this->counts['pending']">{{ __('To review') }}</flux:navbar.item>
            <flux:navbar.item wire:click="$set('tab', 'low')" :current="$tab === 'low'" :badge="$this->counts['low']">{{ __('Low relevance') }}</flux:navbar.item>
            <flux:navbar.item wire:click="$set('tab', 'rejected')" :current="$tab === 'rejected'" :badge="$this->counts['rejected']">{{ __('Rejected') }}</flux:navbar.item>
        </flux:navbar>

        <flux:spacer />

        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" size="sm" :placeholder="__('Search')" class="max-w-52" clearable />

        <flux:select wire:model.live="type" size="sm" class="max-w-40">
            <flux:select.option value="">{{ __('All sources') }}</flux:select.option>
            @foreach ($this->sourceTypes() as $value => $label)
                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($campaign->categories)
            <flux:select wire:model.live="category" size="sm" class="max-w-44">
                <flux:select.option value="">{{ __('All categories') }}</flux:select.option>
                @foreach ($campaign->categories as $option)
                    <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    @if ($this->items->isNotEmpty())
        <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
            <flux:button size="sm" variant="subtle" wire:click="toggleSelectPage" icon="check">{{ __('Select page') }}</flux:button>
            @if ($selected)
                <flux:text>{{ trans_choice(':count selected|:count selected', count($selected)) }}</flux:text>
                @if ($tab === 'rejected')
                    <flux:button size="sm" wire:click="bulk('restore')" icon="arrow-uturn-left">{{ __('Restore') }}</flux:button>
                @else
                    <flux:button size="sm" variant="primary" color="green" wire:click="bulk('approve')" icon="check">{{ __('Approve') }}</flux:button>
                    <flux:button size="sm" variant="danger" wire:click="bulk('reject', 'Not relevant')" icon="x-mark">{{ __('Reject') }}</flux:button>
                @endif
            @endif
            <flux:spacer />
            <flux:text size="sm" class="hidden md:block">{{ __('Keys: J/K move · A approve · R reject · E edit · O open') }}</flux:text>
        </div>
    @endif

    <div class="space-y-3">
        @forelse ($this->items as $item)
            @php($duplicates = $item->cluster_id ? ($this->duplicates[$item->cluster_id] ?? collect()) : collect())
            <div
                wire:key="item-{{ $item->id }}"
                data-review-card
                data-id="{{ $item->id }}"
                data-url="{{ $item->url }}"
                tabindex="0"
                @focus="index = cards().indexOf($el)"
                class="flex gap-4 rounded-xl border border-zinc-200 p-4 outline-none transition focus:border-zinc-500 focus:ring-2 focus:ring-zinc-300 dark:border-zinc-700 dark:focus:border-zinc-400 dark:focus:ring-zinc-600"
            >
                <flux:checkbox wire:model.live="selected" :value="$item->id" class="mt-1" />

                @if ($image = $item->imageUrl())
                    <img src="{{ $image }}" alt="" loading="lazy" class="hidden size-24 shrink-0 rounded-lg object-cover sm:block">
                @endif

                <div class="min-w-0 flex-1 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-item.relevance :item="$item" />
                        @if ($item->ai_category)
                            <flux:badge size="sm" color="blue">{{ $item->ai_category }}</flux:badge>
                        @endif
                        @if ($item->edited_title || $item->edited_summary)
                            <flux:badge size="sm" color="purple" icon="pencil">{{ __('Edited') }}</flux:badge>
                        @endif
                        @if ($item->reject_reason)
                            <flux:badge size="sm" color="red">{{ $item->reject_reason }}</flux:badge>
                        @endif
                    </div>

                    <a href="{{ $item->url }}" target="_blank" rel="noopener noreferrer" class="block text-base font-semibold text-zinc-900 hover:underline dark:text-white">
                        {{ $item->displayTitle() }}
                    </a>

                    <x-item.meta :item="$item" />

                    @if ($summary = $item->displaySummary())
                        <flux:text class="line-clamp-3">{{ $summary }}</flux:text>
                    @endif

                    @if ($fields = collect($item->ai_fields ?? [])->filter(fn ($value) => filled($value)))
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($fields as $key => $value)
                                <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs dark:bg-zinc-800">
                                    <span class="text-zinc-500">{{ $this->fieldLabels[$key] ?? Str::headline($key) }}:</span>
                                    {{ is_array($value) ? implode(', ', $value) : $value }}
                                </span>
                            @endforeach
                        </div>
                    @endif

                    @if ($duplicates->isNotEmpty())
                        <flux:text size="sm">
                            {{ __('Also reported by') }}
                            @foreach ($duplicates as $duplicate)
                                <a href="{{ $duplicate->url }}" target="_blank" rel="noopener noreferrer" class="underline">{{ data_get($duplicate->media, 'publisher.name') ?? $duplicate->source?->name ?? parse_url($duplicate->url, PHP_URL_HOST) }}</a>@if (! $loop->last), @endif
                            @endforeach
                        </flux:text>
                    @endif
                </div>

                <div class="flex shrink-0 flex-col items-end gap-2">
                    @if ($tab === 'rejected')
                        <flux:button size="sm" icon="arrow-uturn-left" wire:click="restore({{ $item->id }})">{{ __('Restore') }}</flux:button>
                    @else
                        <flux:button size="sm" variant="primary" color="green" icon="check" wire:click="approve({{ $item->id }})">{{ __('Approve') }}</flux:button>
                        <flux:button size="sm" icon="pencil-square" wire:click="startEdit({{ $item->id }})">{{ __('Edit') }}</flux:button>
                        <flux:dropdown position="bottom" align="end">
                            <flux:button size="sm" variant="ghost" icon="x-mark" icon:trailing="chevron-down">{{ __('Reject') }}</flux:button>
                            <flux:menu>
                                @foreach (App\Livewire\Campaigns\Review::REJECT_REASONS as $reason)
                                    <flux:menu.item wire:click="reject({{ $item->id }}, @js($reason))">{{ __($reason) }}</flux:menu.item>
                                @endforeach
                                <flux:menu.separator />
                                <flux:menu.item icon="sparkles" wire:click="retryAi({{ $item->id }})">{{ __('Run AI again') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
                <flux:icon.inbox class="mx-auto size-8 text-zinc-400" />
                <flux:heading class="mt-3">{{ $tab === 'rejected' ? __('Nothing rejected') : __('All caught up') }}</flux:heading>
                <flux:text class="mt-1">{{ __('New items appear here as sources are fetched.') }}</flux:text>
            </div>
        @endforelse
    </div>

    <div class="mt-6">{{ $this->items->links() }}</div>

    <flux:modal name="edit-item" class="w-full max-w-2xl">
        <form wire:submit="saveEdit" class="space-y-5">
            <flux:heading size="lg">{{ __('Edit before publishing') }}</flux:heading>
            <flux:input wire:model="editTitle" :label="__('Title')" required />
            <flux:textarea wire:model="editSummary" :label="__('Summary')" rows="6" />
            <div class="flex justify-end gap-2">
                <flux:button wire:click="saveEdit(false)">{{ __('Save only') }}</flux:button>
                <flux:button type="submit" variant="primary" icon="check">{{ __('Save & approve') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
