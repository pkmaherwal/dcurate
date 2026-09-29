<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Campaigns') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Each campaign curates the latest updates for one niche from many sources.') }}</flux:text>
        </div>

        @can('create', App\Models\Campaign::class)
            <flux:modal.trigger name="create-campaign">
                <flux:button variant="primary" icon="plus">{{ __('New campaign') }}</flux:button>
            </flux:modal.trigger>
        @endcan
    </div>

    @if ($this->campaigns->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
            <flux:icon.megaphone class="mx-auto size-8 text-zinc-400" />
            <flux:heading class="mt-3">{{ __('No campaigns yet') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Create one, e.g. "Football tournaments in India", then add its sources.') }}</flux:text>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->campaigns as $campaign)
                <a href="{{ route('campaigns.review', $campaign) }}" wire:navigate wire:key="campaign-{{ $campaign->id }}"
                   class="group rounded-xl border border-zinc-200 p-5 transition hover:border-zinc-400 hover:shadow-sm dark:border-zinc-700 dark:hover:border-zinc-500">
                    <div class="flex items-start justify-between gap-3">
                        <flux:heading class="group-hover:underline">{{ $campaign->name }}</flux:heading>
                        <flux:badge size="sm" :color="$campaign->isActive() ? 'green' : 'zinc'">{{ $campaign->status->label() }}</flux:badge>
                    </div>
                    @if ($campaign->description)
                        <flux:text class="mt-1 line-clamp-2">{{ $campaign->description }}</flux:text>
                    @endif
                    <div class="mt-4 flex flex-wrap gap-2">
                        <flux:badge size="sm" color="amber" icon="inbox-stack">{{ trans_choice(':count to review|:count to review', $campaign->pending_count) }}</flux:badge>
                        <flux:badge size="sm" color="green" icon="check-badge">{{ trans_choice(':count approved|:count approved', $campaign->approved_count) }}</flux:badge>
                        <flux:badge size="sm" icon="rss">{{ trans_choice(':count source|:count sources', $campaign->sources_count) }}</flux:badge>
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    <flux:modal name="create-campaign" class="w-full max-w-lg">
        <form wire:submit="create" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('New campaign') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Pick a template for the AI categories and fields. You can change everything later.') }}</flux:text>
            </div>

            <flux:input wire:model="name" :label="__('Name')" :placeholder="__('Football tournaments in India')" required autofocus />
            <flux:textarea wire:model="description" :label="__('Description')" rows="2" />

            <flux:select wire:model="template" :label="__('Template')">
                @foreach ($this->templates as $key => $label)
                    <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="keywords" :label="__('Keywords')" :description="__('Comma separated. Used for web and news searches.')" :placeholder="__('football tournament, Santosh Trophy')" />

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
                <flux:input wire:model="freshnessDays" type="number" min="1" max="365" :label="__('Keep (days)')" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create campaign') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
