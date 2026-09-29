<div>
    <x-campaign.nav :campaign="$campaign" current="approved" />

    <div class="mb-4 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="sm">{{ __('Published through the API') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Your website reads these items (featured first, then newest) from:') }}</flux:text>
        <code class="mt-2 block overflow-x-auto rounded bg-white px-3 py-2 text-xs dark:bg-zinc-800">GET {{ route('api.campaigns.updates', $campaign->slug) }}</code>
        <flux:text size="sm" class="mt-2">
            {{ __('Authenticate with a workspace API token:') }}
            <flux:link :href="route('workspace.settings')" wire:navigate>{{ __('create one in Workspace settings') }}</flux:link>.
            {{ __('RSS:') }} <code class="text-xs">{{ route('api.campaigns.feed', $campaign->slug) }}?token=…</code>
        </flux:text>
    </div>

    <div class="space-y-3">
        @forelse ($this->items as $item)
            <div wire:key="approved-{{ $item->id }}" class="flex gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                @if ($image = $item->imageUrl())
                    <img src="{{ $image }}" alt="" loading="lazy" class="hidden size-20 shrink-0 rounded-lg object-cover sm:block">
                @endif
                <div class="min-w-0 flex-1 space-y-1.5">
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($item->is_featured)
                            <flux:badge size="sm" color="yellow" icon="star">{{ __('Featured') }}</flux:badge>
                        @endif
                        @if ($item->ai_category)
                            <flux:badge size="sm" color="blue">{{ $item->ai_category }}</flux:badge>
                        @endif
                    </div>
                    <a href="{{ $item->url }}" target="_blank" rel="noopener noreferrer" class="block font-semibold hover:underline">{{ $item->displayTitle() }}</a>
                    <x-item.meta :item="$item" />
                    @if ($summary = $item->displaySummary())
                        <flux:text class="line-clamp-2">{{ $summary }}</flux:text>
                    @endif
                    <flux:text size="sm">{{ __('Approved :when by :name', ['when' => $item->reviewed_at?->diffForHumans(), 'name' => $item->reviewer?->name ?? __('system')]) }}</flux:text>
                </div>
                <div class="flex shrink-0 flex-col items-end gap-2">
                    <flux:button size="sm" :icon="$item->is_featured ? 'star' : 'star'" :variant="$item->is_featured ? 'primary' : 'outline'" wire:click="toggleFeatured({{ $item->id }})">
                        {{ $item->is_featured ? __('Unfeature') : __('Feature') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="unpublish({{ $item->id }})">{{ __('Unpublish') }}</flux:button>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
                <flux:icon.check-badge class="mx-auto size-8 text-zinc-400" />
                <flux:heading class="mt-3">{{ __('Nothing approved yet') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Approve items in the Review tab and they appear here and in the API.') }}</flux:text>
            </div>
        @endforelse
    </div>

    <div class="mt-6">{{ $this->items->links() }}</div>
</div>
